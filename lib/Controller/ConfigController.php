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
		$qb = $this->db->getQueryBuilder();

		// Subquery to get the latest timestamp for each file_id, rule_id combination
		$latestAaTimeQb = $this->db->getQueryBuilder();
		$latestAaTimeQb->select(['file_id', 'rule_id', 'MAX(timestamp) AS max_timestamp'])
			->from('approval_activity')
			->groupBy(['file_id', 'rule_id']);

		// Subquery to get the latest state for each file_id and rule_id, ensuring the file exists
		$latestActivityQb = $this->db->getQueryBuilder();
		$latestActivityQb->select(['aa.file_id', 'aa.rule_id', 'aa.new_state'])
			->from('approval_activity', 'aa')
			->innerJoin(
				'aa',
				'(' . $latestAaTimeQb->getSQL() . ')', // Use the SQL from the previous subquery
				'latest_aa_time',
				$latestActivityQb->expr()->andX(
					$latestActivityQb->expr()->eq('aa.file_id', 'latest_aa_time.file_id'),
					$latestActivityQb->expr()->eq('aa.rule_id', 'latest_aa_time.rule_id'),
					$latestActivityQb->expr()->eq('aa.timestamp', 'latest_aa_time.max_timestamp')
				)
			)
			->innerJoin('aa', 'filecache', 'fc', $latestActivityQb->expr()->eq('aa.file_id', 'fc.fileid'));

		// Main query
		$qb->select([
			'ar.id AS rule_id',
			'ar.description',
			$qb->expr()->sumCase('latest_activity.new_state = 1', 1, 'pending_count'),
			$qb->expr()->sumCase('latest_activity.new_state = 2', 1, 'approved_count'),
			$qb->expr()->sumCase('latest_activity.new_state = 3', 1, 'rejected_count')
		])
			->from('approval_rules', 'ar')
			->leftJoin(
				'ar',
				'(' . $latestActivityQb->getSQL() . ')', // Use the SQL from the latest_activity subquery
				'latest_activity',
				$qb->expr()->eq('ar.id', 'latest_activity.rule_id')
			)
			->groupBy(['ar.id', 'ar.description'])
			->orderBy('ar.id', 'ASC');

		// Set parameters for the subqueries if QueryBuilder handles them globally or pass them down
		// For now, assuming getSQL() bakes in literals if not using named parameters for sub-sub-queries.
		// If parameters are needed for sub-sub-queries, this approach of getSQL() might need refinement
		// or QueryBuilder must support nested parameter propagation.

		$stmt = $qb->execute();
		$results = $stmt->fetchAll();
		$stmt->closeCursor();

		$kpis = [];
		foreach ($results as $row) {
			// Ensure counts are integers
			$kpis[] = [
				'rule_id' => (int)$row['rule_id'],
				'description' => $row['description'],
				'pending_count' => (int)($row['pending_count'] ?? 0),
				'approved_count' => (int)($row['approved_count'] ?? 0),
				'rejected_count' => (int)($row['rejected_count'] ?? 0),
			];
		}

		// Ensure all rules are present, even if they have no activity
		$allRules = $this->ruleService->getRules();
		$kpisRuleIds = array_column($kpis, 'rule_id');

		foreach ($allRules as $rule) {
			if (!in_array((int)$rule['id'], $kpisRuleIds)) {
				$kpis[] = [
					'rule_id' => (int)$rule['id'],
					'description' => $rule['description'],
					'pending_count' => 0,
					'approved_count' => 0,
					'rejected_count' => 0,
				];
			}
		}
		// Sort again by rule_id if new rules were added
		usort($kpis, function($a, $b) {
			return $a['rule_id'] <=> $b['rule_id'];
		});

		return new DataResponse($kpis);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getAllApprovalFiles(): DataResponse {
		$qb = $this->db->getQueryBuilder();

		// Subquery for distinct file_id, rule_id pairs
		$distinctPairsQb = $this->db->getQueryBuilder();
		$distinctPairsQb->selectDistinct(['file_id', 'rule_id'])
			->from('approval_activity', 'apa'); // Alias for clarity in main query if needed

		// Subquery for latest status and its timestamp
		$latestStatusSubQb = $this->db->getQueryBuilder();
		$latestStatusInnerQb = $this->db->getQueryBuilder();
		$latestStatusInnerQb->select(['file_id', 'rule_id', 'MAX(timestamp) AS max_ts'])
			->from('approval_activity')
			->groupBy(['file_id', 'rule_id']);

		$latestStatusSubQb->select(['act.file_id', 'act.rule_id', 'act.new_state AS status_code_val', 'act.timestamp AS activity_timestamp_val'])
			->from('approval_activity', 'act')
			->innerJoin(
				'act',
				'(' . $latestStatusInnerQb->getSQL() . ')',
				'latest_ts_info',
				$latestStatusSubQb->expr()->andX(
					$latestStatusSubQb->expr()->eq('act.file_id', 'latest_ts_info.file_id'),
					$latestStatusSubQb->expr()->eq('act.rule_id', 'latest_ts_info.rule_id'),
					$latestStatusSubQb->expr()->eq('act.timestamp', 'latest_ts_info.max_ts')
				)
			);

		// Subquery for sent_at (earliest timestamp)
		$sentAtSubQb = $this->db->getQueryBuilder();
		$sentAtSubQb->select(['file_id', 'rule_id', 'MIN(timestamp) AS sent_at_val'])
			->from('approval_activity')
			->groupBy(['file_id', 'rule_id']);

		// Subquery for approved_at (latest approval timestamp)
		$approvedAtSubQb = $this->db->getQueryBuilder();
		$approvedAtSubQb->select(['file_id', 'rule_id', 'MAX(timestamp) AS approved_at_val'])
			->from('approval_activity')
			->where($approvedAtSubQb->expr()->eq('new_state', $approvedAtSubQb->createNamedParameter(2, IQueryBuilder::PARAM_INT)))
			->groupBy(['file_id', 'rule_id']);

		// Subquery for rejected_at (latest rejection timestamp)
		$rejectedAtSubQb = $this->db->getQueryBuilder();
		$rejectedAtSubQb->select(['file_id', 'rule_id', 'MAX(timestamp) AS rejected_at_val'])
			->from('approval_activity')
			->where($rejectedAtSubQb->expr()->eq('new_state', $rejectedAtSubQb->createNamedParameter(3, IQueryBuilder::PARAM_INT)))
			->groupBy(['file_id', 'rule_id']);

		// Main query joining all subqueries
		$qb->select([
			'aa_main.file_id',
			'aa_main.rule_id',
			'fc.path',
			'ls.status_code_val AS status_code',
			'ls.activity_timestamp_val AS activity_timestamp',
			'sa.sent_at_val AS sent_at',
			'app_at.approved_at_val AS approved_at',
			'rej_at.rejected_at_val AS rejected_at'
		])
		->from('(' . $distinctPairsQb->getSQL() . ')', 'aa_main')
		->innerJoin('aa_main', 'filecache', 'fc', $qb->expr()->eq('aa_main.file_id', 'fc.fileid'))
		->leftJoin(
			'aa_main',
			'(' . $latestStatusSubQb->getSQL() . ')',
			'ls',
			$qb->expr()->andX(
				$qb->expr()->eq('aa_main.file_id', 'ls.file_id'),
				$qb->expr()->eq('aa_main.rule_id', 'ls.rule_id')
			)
		)
		->leftJoin(
			'aa_main',
			'(' . $sentAtSubQb->getSQL() . ')',
			'sa',
			$qb->expr()->andX(
				$qb->expr()->eq('aa_main.file_id', 'sa.file_id'),
				$qb->expr()->eq('aa_main.rule_id', 'sa.rule_id')
			)
		)
		->leftJoin(
			'aa_main',
			'(' . $approvedAtSubQb->getSQL() . ')',
			'app_at',
			$qb->expr()->andX(
				$qb->expr()->eq('aa_main.file_id', 'app_at.file_id'),
				$qb->expr()->eq('aa_main.rule_id', 'app_at.rule_id')
			)
		)
		->leftJoin(
			'aa_main',
			'(' . $rejectedAtSubQb->getSQL() . ')',
			'rej_at',
			$qb->expr()->andX(
				$qb->expr()->eq('aa_main.file_id', 'rej_at.file_id'),
				$qb->expr()->eq('aa_main.rule_id', 'rej_at.rule_id')
			)
		)
		->orderBy('aa_main.file_id', 'ASC')
		->addOrderBy('aa_main.rule_id', 'ASC');

		// Handle parameters from subqueries
		// Need to collect parameters from $approvedAtSubQb and $rejectedAtSubQb
		$parameters = array_merge(
			$approvedAtSubQb->getParameters(),
			$rejectedAtSubQb->getParameters()
		);
		$qb->setParameters($parameters);

		$stmt = $qb->execute();
		$results = $stmt->fetchAllAssociative();
		$stmt->closeCursor();

		$allFilesData = array_map(function($row) {
			return [
				'file_id' => (int)$row['file_id'],
				'rule_id' => (int)$row['rule_id'],
				'path' => $row['path'],
				'status_code' => $row['status_code'] !== null ? (int)$row['status_code'] : null,
				'activity_timestamp' => $row['activity_timestamp'] !== null ? (int)$row['activity_timestamp'] : null,
				'sent_at' => $row['sent_at'] !== null ? (int)$row['sent_at'] : null,
				'approved_at' => $row['approved_at'] !== null ? (int)$row['approved_at'] : null,
				'rejected_at' => $row['rejected_at'] !== null ? (int)$row['rejected_at'] : null,
			];
		}, $results);

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

	/**
	 * Reset all approval data (rules, activity, etc.)
	 * This is an admin-only action.
	 * @return DataResponse
	 */
	public function resetAllData(): DataResponse {
		try {
			// Clear approval_activity table
			$qbActivity = $this->db->getQueryBuilder();
			$qbActivity->delete('approval_activity');
			$qbActivity->executeStatement();

			// Clear approval_rule_approvers table
			$qbApprovers = $this->db->getQueryBuilder();
			$qbApprovers->delete('approval_rule_approvers');
			$qbApprovers->executeStatement();

			// Clear approval_rule_requesters table
			$qbRequesters = $this->db->getQueryBuilder();
			$qbRequesters->delete('approval_rule_requesters');
			$qbRequesters->executeStatement();

			// Clear approval_rules table
			$qbRules = $this->db->getQueryBuilder();
			$qbRules->delete('approval_rules');
			$qbRules->executeStatement();

			// Optionally, reset auto-increment counters if using TRUNCATE (requires specific DB commands and more privileges)
			// For simplicity and safety here, we're just deleting rows.
			// Admin should be advised to manually clean up system tags if desired.

			return new DataResponse(['status' => 'success', 'message' => 'All approval data has been reset.']);
		} catch (\Exception $e) {
			// Log the exception if possible
			// $this->logger->error("Failed to reset approval data: " . $e->getMessage());
			return new DataResponse(['status' => 'error', 'message' => 'Failed to reset approval data. Check server logs.'], 500);
		}
	}

	/**
	 * Reset only the approval activity for all files, keeping workflow rules.
	 * This is an admin-only action.
	 * @return DataResponse
	 */
	public function resetApprovalActivity(): DataResponse {
		try {
			// Clear approval_activity table
			$qbActivity = $this->db->getQueryBuilder();
			$qbActivity->delete('approval_activity');
			$qbActivity->executeStatement();

			// Note: System tags on files are not automatically removed by this action.
			// Admins might need to manually clear relevant tags from files if they want a complete visual reset for users.

			return new DataResponse(['status' => 'success', 'message' => 'All file approval statuses and history have been reset. Workflow rules remain.']);
		} catch (\Exception $e) {
			// Log the exception if possible
			// $this->logger->error("Failed to reset approval activity: " . $e->getMessage());
			return new DataResponse(['status' => 'error', 'message' => 'Failed to reset approval activity. Check server logs.'], 500);
		}
	}
}
