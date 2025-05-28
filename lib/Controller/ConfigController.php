<?php

/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Approval\Controller;

use OCA\Approval\Service\RuleService;
use OCA\Approval\Service\UtilsService;

use OCP\App\IAppManager;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

use OCP\IUserManager;
use OCP\IDBConnection;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\DB\QueryBuilder\IQueryBuilder;

use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;

class ConfigController extends Controller {

	public function __construct(
		$appName,
		IRequest $request,
		private IUserManager $userManager,
		private IAppManager $appManager,
		private RuleService $ruleService,
		private UtilsService $utilsService,
		private ?string $userId,
		private IDBConnection $db,
		private IRootFolder $rootFolder
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * create a tag
	 *
	 * @param string $name of the new tag
	 * @return DataResponse
	 */
	public function createTag(string $name): DataResponse {
		$result = $this->utilsService->createTag($name);
		if (isset($result['error'])) {
			return new DataResponse($result, 400);
		} else {
			return new DataResponse($result);
		}
	}

	/**
	 *
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getRules(): DataResponse {
		$circlesEnabled = $this->appManager->isEnabledForUser('circles') && class_exists(\OCA\Circles\CirclesManager::class);
		if ($circlesEnabled) {
			$circlesManager = \OC::$server->get(\OCA\Circles\CirclesManager::class);
			$circlesManager->startSuperSession();
		}

		$rules = $this->ruleService->getRules();
		foreach ($rules as $id => $rule) {
			foreach ($rule['approvers'] as $k => $elem) {
				if ($elem['type'] === 'user') {
					$user = $this->userManager->get($elem['entityId']);
					$rules[$id]['approvers'][$k]['displayName'] = $user ? $user->getDisplayName() : $elem['entityId'];
				} elseif ($elem['type'] === 'group') {
					$rules[$id]['approvers'][$k]['displayName'] = $elem['entityId'];
				} elseif ($elem['type'] === 'circle') {
					if ($circlesEnabled) {
						try {
							$circle = $circlesManager->getCircle($elem['entityId']);
							$rules[$id]['approvers'][$k]['displayName'] = $circle->getDisplayName();
						} catch (\OCA\Circles\Exceptions\CircleNotFoundException $e) {
						}
					} else {
						unset($rules[$id]['approvers'][$k]);
					}
				}
			}
			foreach ($rule['requesters'] as $k => $elem) {
				if ($elem['type'] === 'user') {
					$user = $this->userManager->get($elem['entityId']);
					$rules[$id]['requesters'][$k]['displayName'] = $user ? $user->getDisplayName() : $elem['entityId'];
				} elseif ($elem['type'] === 'group') {
					$rules[$id]['requesters'][$k]['displayName'] = $elem['entityId'];
				} elseif ($elem['type'] === 'circle') {
					if ($circlesEnabled) {
						try {
							$circle = $circlesManager->getCircle($elem['entityId']);
							$rules[$id]['requesters'][$k]['displayName'] = $circle->getDisplayName();
						} catch (\OCA\Circles\Exceptions\CircleNotFoundException $e) {
						}
					} else {
						unset($rules[$id]['requesters'][$k]);
					}
				}
			}
		}
		if ($circlesEnabled) {
			$circlesManager->stopSession();
		}
		return new DataResponse($rules);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getWorkflowKpis(): DataResponse {
		$rules = $this->ruleService->getRules();
		$kpis = [];

		$qb = $this->db->getQueryBuilder();
		// Select file_id, rule_id, and new_state. The subsequent processing will count distinct files.
		$qb->select(['file_id', 'rule_id', 'new_state'])
			->from('approval_activity')
			->groupBy(['file_id', 'rule_id', 'new_state']);

		$stmt = $qb->execute();
		$results = $stmt->fetchAll();
		$stmt->closeCursor();

		$actionCountsByRule = [];
		foreach ($results as $row) {
			if (!isset($actionCountsByRule[$row['rule_id']])) {
				$actionCountsByRule[$row['rule_id']] = [
					1 => 0, // Pending
					2 => 0, // Approved
					3 => 0, // Rejected
				];
			}
			// We are counting distinct files per state for a rule
			// The query gives us one row per distinct file_id per rule_id per new_state
			$actionCountsByRule[$row['rule_id']][(int)$row['new_state']]++;
		}

		foreach ($rules as $rule) {
			$ruleId = (int)$rule['id'];
			$kpis[] = [
				'rule_id' => $ruleId,
				'description' => $rule['description'],
				'pending_count' => $actionCountsByRule[$ruleId][1] ?? 0,
				'approved_count' => $actionCountsByRule[$ruleId][2] ?? 0,
				'rejected_count' => $actionCountsByRule[$ruleId][3] ?? 0,
			];
		}

		return new DataResponse($kpis);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getAllApprovalFiles(): DataResponse {
		$allFilesData = [];
		$qb = $this->db->getQueryBuilder();

		// Step 1: Get all distinct (file_id, rule_id) pairs that have had a 'pending' status,
		// indicating an approval process was initiated for that file under that rule.
		// We also fetch the latest status for each pair.
		$qb->selectDistinct(['aa.file_id', 'aa.rule_id'])
			->from('approval_activity', 'aa')
			->where($qb->expr()->eq('aa.new_state', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))); // State_Pending

		$stmt = $qb->execute();
		$fileRulePairs = $stmt->fetchAll();
		$stmt->closeCursor();

		if (empty($fileRulePairs)) {
			return new DataResponse([]);
		}

		foreach ($fileRulePairs as $pair) {
			$fileId = (int)$pair['file_id'];
			$ruleId = (int)$pair['rule_id'];

			try {
				$nodes = $this->rootFolder->getById($fileId);
				if (empty($nodes)) {
					continue; // Skip if node not found
				}
				$node = $nodes[0];
				$filePath = $node->getPath();

				// Step 2: For this specific (file_id, rule_id) pair, get its current status
				$qbStatus = $this->db->getQueryBuilder();
				$qbStatus->select('new_state')
					->from('approval_activity')
					->where($qbStatus->expr()->eq('file_id', $qbStatus->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
					->andWhere($qbStatus->expr()->eq('rule_id', $qbStatus->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)))
					->orderBy('timestamp', 'DESC')
					->setMaxResults(1);
				$stmtStatus = $qbStatus->execute();
				$currentStatusRow = $stmtStatus->fetch();
				$stmtStatus->closeCursor();
				$currentStatusCode = $currentStatusRow ? (int)$currentStatusRow['new_state'] : null;

				// Step 3: Get timestamps for sent, approved, rejected for this specific (file_id, rule_id)
				$sentAt = null;
				$approvedAt = null;
				$rejectedAt = null;

				// Sent At (first pending for this file-rule instance)
				$qbSent = $this->db->getQueryBuilder();
				$qbSent->select('timestamp')
					->from('approval_activity')
					->where($qbSent->expr()->eq('file_id', $qbSent->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
					->andWhere($qbSent->expr()->eq('rule_id', $qbSent->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)))
					->andWhere($qbSent->expr()->eq('new_state', $qbSent->createNamedParameter(1, IQueryBuilder::PARAM_INT))) // STATE_PENDING
					->orderBy('timestamp', 'ASC')
					->setMaxResults(1);
				$stmtSent = $qbSent->execute();
				$sentRow = $stmtSent->fetch();
				if ($sentRow) {
					$sentAt = (int)$sentRow['timestamp'];
				}
				$stmtSent->closeCursor();

				// Approved At (latest approved for this file-rule instance)
				$qbApproved = $this->db->getQueryBuilder();
				$qbApproved->select('timestamp')
					->from('approval_activity')
					->where($qbApproved->expr()->eq('file_id', $qbApproved->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
					->andWhere($qbApproved->expr()->eq('rule_id', $qbApproved->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)))
					->andWhere($qbApproved->expr()->eq('new_state', $qbApproved->createNamedParameter(2, IQueryBuilder::PARAM_INT))) // STATE_APPROVED
					->orderBy('timestamp', 'DESC')
					->setMaxResults(1);
				$stmtApproved = $qbApproved->execute();
				$approvedRow = $stmtApproved->fetch();
				if ($approvedRow) {
					$approvedAt = (int)$approvedRow['timestamp'];
				}
				$stmtApproved->closeCursor();

				// Rejected At (latest rejected for this file-rule instance)
				$qbRejected = $this->db->getQueryBuilder();
				$qbRejected->select('timestamp')
					->from('approval_activity')
					->where($qbRejected->expr()->eq('file_id', $qbRejected->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
					->andWhere($qbRejected->expr()->eq('rule_id', $qbRejected->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)))
					->andWhere($qbRejected->expr()->eq('new_state', $qbRejected->createNamedParameter(3, IQueryBuilder::PARAM_INT))) // STATE_REJECTED
					->orderBy('timestamp', 'DESC')
					->setMaxResults(1);
				$stmtRejected = $qbRejected->execute();
				$rejectedRow = $stmtRejected->fetch();
				if ($rejectedRow) {
					$rejectedAt = (int)$rejectedRow['timestamp'];
				}
				$stmtRejected->closeCursor();

				$allFilesData[] = [
					'file_id' => $fileId,
					'path' => $filePath,
					'rule_id' => $ruleId,
					'status_code' => $currentStatusCode,
					'sent_at' => $sentAt,
					'approved_at' => $approvedAt,
					'rejected_at' => $rejectedAt,
				];
			} catch (NotFoundException $e) {
				// File might have been deleted, skip this instance
				$this->logger->debug("File with ID $fileId not found, skipping for getAllApprovalFiles.", ['exception' => $e]);
				continue;
			} catch (\Throwable $e) {
				// Log and skip any other error for this specific file-rule pair
				$this->logger->error("Error processing file-rule pair ($fileId, $ruleId) in getAllApprovalFiles: " . $e->getMessage(), ['exception' => $e]);
				continue;
			}
		}

		return new DataResponse($allFilesData);
	}

	/**
	 * @param int $tagPending
	 * @param int $tagApproved
	 * @param int $tagRejected
	 * @param array $approvers
	 * @param array $requesters
	 * @param string $description
	 * @return DataResponse
	 */
	public function createRule(int $tagPending, int $tagApproved, int $tagRejected,
		array $approvers, array $requesters, string $description): DataResponse {
		$result = $this->ruleService->createRule($tagPending, $tagApproved, $tagRejected, $approvers, $requesters, $description);
		return isset($result['error'])
			? new DataResponse($result, 400)
			: new DataResponse($result['id']);
	}

	/**
	 * @param int $id
	 * @param int $tagPending
	 * @param int $tagApproved
	 * @param int $tagRejected
	 * @param array $approvers
	 * @param array $requesters
	 * @param string $description
	 * @return DataResponse
	 */
	public function saveRule(int $id, int $tagPending, int $tagApproved, int $tagRejected,
		array $approvers, array $requesters, string $description): DataResponse {
		$result = $this->ruleService->saveRule($id, $tagPending, $tagApproved, $tagRejected, $approvers, $requesters, $description);
		return isset($result['error'])
			? new DataResponse($result, 400)
			: new DataResponse($result['id']);
	}

	/**
	 * @param int $id
	 * @return DataResponse
	 */
	public function deleteRule(int $id): DataResponse {
		$result = $this->ruleService->deleteRule($id);
		return isset($result['error'])
			? new DataResponse($result, 400)
			: new DataResponse();
	}
}
