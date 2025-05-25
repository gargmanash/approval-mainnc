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
use OCP\IQueryBuilder;

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

	public function getAllApprovalFiles(): DataResponse {
		$qb = $this->db->getQueryBuilder();

		// Alias for the main table
		$mainAlias = 'aa';
		// Alias for the subquery
		$subAlias = 'latest_aa';

		// Step 1: Define the subquery to get the latest timestamp for each file_id
		$subQuery = $this->db->getQueryBuilder();
		$subQuery->select('file_id')
			->addSelect('MAX(' . $this->db->quoteIdentifier('timestamp') . ') AS max_timestamp')
			->from('approval_activity')
			->groupBy('file_id');

		// Step 2: Build the main query
		$qb->select($mainAlias . '.file_id', $mainAlias . '.rule_id', $mainAlias . '.new_state', $mainAlias . '.timestamp')
			->from('approval_activity', $mainAlias)
			->innerJoin(
				$mainAlias,
				'(' . $subQuery->getSQL() . ')', // The subquery SQL
				$subAlias, // Alias for the subquery result
				$qb->expr()->andX( // Join condition
					$qb->expr()->eq($mainAlias . '.file_id', $subAlias . '.file_id'),
					$qb->expr()->eq($mainAlias . '.timestamp', $subAlias . '.max_timestamp')
				)
			);

		$stmt = $qb->execute();
		$results = $stmt->fetchAll();
		$stmt->closeCursor();

		$allFilesData = [];
		foreach ($results as $row) {
			try {
				$nodes = $this->rootFolder->getById((int)$row['file_id']);
				if (!empty($nodes)) {
					$node = $nodes[0];
					$allFilesData[] = [
						'file_id' => (int)$row['file_id'],
						'path' => $node->getPath(),
						'rule_id' => (int)$row['rule_id'],
						'status_code' => (int)$row['new_state'], // 1:pending, 2:approved, 3:rejected
						'timestamp' => (int)$row['timestamp'],
					];
				}
			} catch (NotFoundException $e) {
				// File might have been deleted, skip it
				// Consider logging this if $this->logger is available and configured
				// For now, just skipping.
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
