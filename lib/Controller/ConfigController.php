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
			->from('approval_activity');

		// Correlated subquery for status_code
		$statusQb = $this->db->getQueryBuilder();
		$statusQb->select('new_state')
			->from('approval_activity', 'aa_latest')
			->where($statusQb->expr()->eq('aa_latest.file_id', 'aa_main.file_id'))
			->andWhere($statusQb->expr()->eq('aa_latest.rule_id', 'aa_main.rule_id'))
			->orderBy('aa_latest.timestamp', 'DESC')
			->setMaxResults(1);

		// Correlated subquery for activity_timestamp (timestamp of the latest status)
		$activityTsQb = $this->db->getQueryBuilder();
		$activityTsQb->select('timestamp')
			->from('approval_activity', 'aa_latest_ts')
			->where($activityTsQb->expr()->eq('aa_latest_ts.file_id', 'aa_main.file_id'))
			->andWhere($activityTsQb->expr()->eq('aa_latest_ts.rule_id', 'aa_main.rule_id'))
			->orderBy('aa_latest_ts.timestamp', 'DESC')
			->setMaxResults(1);

		// Correlated subquery for sent_at
		$sentAtQb = $this->db->getQueryBuilder();
		$sentAtQb->select('MIN(aa_sent.timestamp)')
			->from('approval_activity', 'aa_sent')
			->where($sentAtQb->expr()->eq('aa_sent.file_id', 'aa_main.file_id'))
			->andWhere($sentAtQb->expr()->eq('aa_sent.rule_id', 'aa_main.rule_id'));

		// Correlated subquery for approved_at
		$approvedAtQb = $this->db->getQueryBuilder();
		$approvedAtQb->select('MAX(aa_approved.timestamp)')
			->from('approval_activity', 'aa_approved')
			->where($approvedAtQb->expr()->eq('aa_approved.file_id', 'aa_main.file_id'))
			->andWhere($approvedAtQb->expr()->eq('aa_approved.rule_id', 'aa_main.rule_id'))
			->andWhere($approvedAtQb->expr()->eq('aa_approved.new_state', $approvedAtQb->createNamedParameter(2, IQueryBuilder::PARAM_INT)));

		// Correlated subquery for rejected_at
		$rejectedAtQb = $this->db->getQueryBuilder();
		$rejectedAtQb->select('MAX(aa_rejected.timestamp)')
			->from('approval_activity', 'aa_rejected')
			->where($rejectedAtQb->expr()->eq('aa_rejected.file_id', 'aa_main.file_id'))
			->andWhere($rejectedAtQb->expr()->eq('aa_rejected.rule_id', 'aa_main.rule_id'))
			->andWhere($rejectedAtQb->expr()->eq('aa_rejected.new_state', $rejectedAtQb->createNamedParameter(3, IQueryBuilder::PARAM_INT)));

		$qb->select([
			'aa_main.file_id',
			'aa_main.rule_id',
			'fc.path',
			'(' . $statusQb->getSQL() . ') AS status_code',
			'(' . $activityTsQb->getSQL() . ') AS activity_timestamp',
			'(' . $sentAtQb->getSQL() . ') AS sent_at',
			'(' . $approvedAtQb->getSQL() . ') AS approved_at',
			'(' . $rejectedAtQb->getSQL() . ') AS rejected_at'
		])
			->from('(' . $distinctPairsQb->getSQL() . ')', 'aa_main')
			->innerJoin('aa_main', 'filecache', 'fc', $qb->expr()->eq('aa_main.file_id', 'fc.fileid'))
			->orderBy('aa_main.file_id', 'ASC')
			->addOrderBy('aa_main.rule_id', 'ASC');

		$stmt = $qb->execute();
		$results = $stmt->fetchAllAssociative(); // Use fetchAllAssociative for direct array output
		$stmt->closeCursor();

		// Post-process to ensure correct types and structure if needed, though fetchAllAssociative is good.
		// The subselects should return NULL if no record matches, which is desired.
		// status_code, sent_at, approved_at, rejected_at might need casting to int if not null.

		$allFilesData = array_map(function($row) {
			return [
				'file_id' => (int)$row['file_id'],
				'rule_id' => (int)$row['rule_id'],
				'path' => $row['path'],
				'status_code' => $row['status_code'] !== null ? (int)$row['status_code'] : null,
				// activity_timestamp is used for frontend display of approved_at or rejected_at directly, if status is 2 or 3.
				// We will derive it based on status_code and specific timestamps for clarity in frontend if needed,
				// but backend provides the latest activity's timestamp and specific approval/rejection ones.
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
