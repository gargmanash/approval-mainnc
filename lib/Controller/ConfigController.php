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
		$prefix = \OC::$server->getConfig()->getSystemValue('dbtableprefix', 'oc_');
		$sql = "
			SELECT
				ar.id AS rule_id,
				ar.description,
				COALESCE(SUM(CASE WHEN la.new_state = 1 THEN 1 ELSE 0 END), 0) AS pending_count,
				COALESCE(SUM(CASE WHEN la.new_state = 2 THEN 1 ELSE 0 END), 0) AS approved_count,
				COALESCE(SUM(CASE WHEN la.new_state = 3 THEN 1 ELSE 0 END), 0) AS rejected_count
			FROM
				{$prefix}approval_rules ar
			LEFT JOIN (
				SELECT
					aa_inner.file_id,
					aa_inner.rule_id,
					aa_inner.new_state
				FROM
					{$prefix}approval_activity aa_inner
				INNER JOIN (
					SELECT
						file_id,
						rule_id,
						MAX(timestamp) AS max_timestamp
					FROM
						{$prefix}approval_activity
					GROUP BY
						file_id, rule_id
				) latest_aa_time ON aa_inner.file_id = latest_aa_time.file_id
									AND aa_inner.rule_id = latest_aa_time.rule_id
									AND aa_inner.timestamp = latest_aa_time.max_timestamp
				INNER JOIN
					{$prefix}filecache fc ON aa_inner.file_id = fc.fileid
			) la ON ar.id = la.rule_id
			GROUP BY
				ar.id, ar.description
			ORDER BY
				ar.id;
		";

		$stmt = $this->db->executeQuery($sql);
		$results = [];
		while ($row = $stmt->fetchAssociative()) {
			$results[] = $row;
		}

		$kpis = array_map(function($row) {
			return [
				'rule_id' => (int)$row['rule_id'],
				'description' => $row['description'],
				'pending_count' => (int)$row['pending_count'],
				'approved_count' => (int)$row['approved_count'],
				'rejected_count' => (int)$row['rejected_count'],
			];
		}, $results);

		// Ensure all rules are present, even if they have no activity (already handled by LEFT JOIN and COALESCE)
		// However, if a rule was created and *never* had any file associated (even non-existent ones),
		// it might still be missing. The existing logic below can catch truly orphaned rules.
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
		usort($kpis, function($a, $b) {
			return $a['rule_id'] <=> $b['rule_id'];
		});

		return new DataResponse($kpis);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getAllApprovalFiles(): DataResponse {
		$prefix = \OC::$server->getConfig()->getSystemValue('dbtableprefix', 'oc_');
		$sql = "
			SELECT
				dp.file_id,
				dp.rule_id,
				fc.path,
				ls.status_code_val AS status_code,
				ls.activity_timestamp_val AS activity_timestamp,
				sa.sent_at_val AS sent_at,
				app_at.approved_at_val AS approved_at,
				rej_at.rejected_at_val AS rejected_at
			FROM
				(SELECT DISTINCT file_id, rule_id FROM {$prefix}approval_activity) dp
			INNER JOIN
				{$prefix}filecache fc ON dp.file_id = fc.fileid
			LEFT JOIN (
				SELECT
					act.file_id, act.rule_id, act.new_state AS status_code_val, act.timestamp AS activity_timestamp_val
				FROM {$prefix}approval_activity act
				INNER JOIN (
					SELECT file_id, rule_id, MAX(timestamp) AS max_ts
					FROM {$prefix}approval_activity GROUP BY file_id, rule_id
				) latest_ts_info ON act.file_id = latest_ts_info.file_id AND act.rule_id = latest_ts_info.rule_id AND act.timestamp = latest_ts_info.max_ts
			) ls ON dp.file_id = ls.file_id AND dp.rule_id = ls.rule_id
			LEFT JOIN (
				SELECT file_id, rule_id, MIN(timestamp) AS sent_at_val
				FROM {$prefix}approval_activity GROUP BY file_id, rule_id
			) sa ON dp.file_id = sa.file_id AND dp.rule_id = sa.rule_id
			LEFT JOIN (
				SELECT file_id, rule_id, MAX(timestamp) AS approved_at_val
				FROM {$prefix}approval_activity WHERE new_state = :stateApproved GROUP BY file_id, rule_id
			) app_at ON dp.file_id = app_at.file_id AND dp.rule_id = app_at.rule_id
			LEFT JOIN (
				SELECT file_id, rule_id, MAX(timestamp) AS rejected_at_val
				FROM {$prefix}approval_activity WHERE new_state = :stateRejected GROUP BY file_id, rule_id
			) rej_at ON dp.file_id = rej_at.file_id AND dp.rule_id = rej_at.rule_id
			ORDER BY
				dp.file_id ASC, dp.rule_id ASC;
		";

		$params = [
			'stateApproved' => 2,
			'stateRejected' => 3
		];

		$stmt = $this->db->executeQuery($sql, $params);
		$results = [];
		while ($row = $stmt->fetchAssociative()) {
			$results[] = $row;
		}

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
