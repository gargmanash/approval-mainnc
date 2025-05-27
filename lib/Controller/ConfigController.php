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
		// Step 1: Get all file_ids in approval_activity
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('file_id')
			->from('approval_activity');
		$stmt = $qb->execute();
		$fileIds = [];
		while ($row = $stmt->fetch()) {
			$fileIds[] = $row['file_id'];
		}
		$stmt->closeCursor();

		if (empty($fileIds)) {
			return new DataResponse([]);
		}

		$allFilesData = [];
		foreach ($fileIds as $fileId) {
			// For each file, get the latest timestamp for each status
			$statusTimestamps = [
				1 => null, // Pending
				2 => null, // Approved
				3 => null, // Rejected
			];
			$qb2 = $this->db->getQueryBuilder();
			$qb2->select('new_state', 'timestamp');
			$qb2->from('approval_activity');
			$qb2->where($qb2->expr()->eq('file_id', $qb2->createNamedParameter($fileId)));
			$stmt2 = $qb2->execute();
			while ($row2 = $stmt2->fetch()) {
				$state = (int)$row2['new_state'];
				$ts = (int)$row2['timestamp'];
				if (!isset($statusTimestamps[$state]) || $ts > $statusTimestamps[$state]) {
					$statusTimestamps[$state] = $ts;
				}
			}
			$stmt2->closeCursor();

			// Get the latest rule_id and status_code for this file (as before)
			$qb3 = $this->db->getQueryBuilder();
			$qb3->select('rule_id', 'new_state')
				->from('approval_activity')
				->where($qb3->expr()->eq('file_id', $qb3->createNamedParameter($fileId)))
				->orderBy('timestamp', 'DESC')
				->setMaxResults(1);
			$stmt3 = $qb3->execute();
			$row3 = $stmt3->fetch();
			$stmt3->closeCursor();

			try {
				$nodes = $this->rootFolder->getById((int)$fileId);
				if (!empty($nodes)) {
					$node = $nodes[0];
					$allFilesData[] = [
						'file_id' => (int)$fileId,
						'path' => $node->getPath(),
						'rule_id' => isset($row3['rule_id']) ? (int)$row3['rule_id'] : null,
						'status_code' => isset($row3['new_state']) ? (int)$row3['new_state'] : null,
						'sent_at' => $statusTimestamps[1],
						'approved_at' => $statusTimestamps[2],
						'rejected_at' => $statusTimestamps[3],
					];
				}
			} catch (NotFoundException $e) {
				// File might have been deleted, skip it
				continue;
			} catch (\Throwable $e) {
				// Log and skip any other error
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

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function testAccess(): DataResponse {
		return new DataResponse(['ok' => true, 'user' => $this->userId]);
	}
}
