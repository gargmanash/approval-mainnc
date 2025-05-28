<?php

/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Approval\Service;

use DateTime;
use OCA\Approval\Activity\ActivityManager;
use OCA\Approval\AppInfo\Application;
use OCA\DAV\Connector\Sabre\Node as SabreNode;
use OCP\App\IAppManager;
use OCP\Files\FileInfo;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;

use OCP\Notification\IManager as INotificationManager;
use OCP\Share\IManager as IShareManager;

use OCP\Share\IShare;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use Psr\Log\LoggerInterface;

use Sabre\DAV\INode;
use Sabre\DAV\PropFind;

class ApprovalService {

	private string $appName;

	public function __construct(
		string $appName,
		private ISystemTagObjectMapper $tagObjectMapper,
		private IRootFolder $root,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private IAppManager $appManager,
		private INotificationManager $notificationManager,
		private RuleService $ruleService,
		private ActivityManager $activityManager,
		private UtilsService $utilsService,
		private IShareManager $shareManager,
		private IL10N $l10n,
		private LoggerInterface $logger,
		private ?string $userId) {
		$this->appName = $appName;
	}

	/**
	 * @param string $userId
	 * @param string $role
	 * @return array
	 */
	public function getBasicUserRules(string $userId, string $role): array {
		$userRules = [];
		$rules = $this->ruleService->getRules();

		foreach ($rules as $rule) {
			if ($this->userIsAuthorizedByRule($userId, $rule, $role)) {
				$userRules[] = $rule;
			}
		}
		return $userRules;
	}

	/**
	 * Get rules allowing user to request/approve
	 * If file ID is provided and role is requesters, avoid the rules for which the file is already pending/approved/rejected
	 *
	 * @param string $userId
	 * @param string $role
	 * @param int|null $fileId
	 * @return array
	 */
	public function getUserRules(string $userId, string $role = 'requesters', ?int $fileId = null): array {
		$userRules = [];
		$rules = $this->ruleService->getRules();

		$circlesEnabled = $this->appManager->isEnabledForUser('circles') && class_exists(\OCA\Circles\CirclesManager::class);
		$userNames = [];
		$circleNames = [];
		foreach ($rules as $rule) {
			if ($this->userIsAuthorizedByRule($userId, $rule, $role)) {
				// if looking for requester rules and we have a file ID:
				// avoid if it's already pending/approved/rejected for this rule
				if ($role === 'requesters'
					&& $fileId !== null
					&& ($this->tagObjectMapper->haveTag((string) $fileId, 'files', $rule['tagPending'])
						|| $this->tagObjectMapper->haveTag((string) $fileId, 'files', $rule['tagApproved'])
						|| $this->tagObjectMapper->haveTag((string) $fileId, 'files', $rule['tagRejected'])
					)
				) {
					continue;
				}
				$userRules[] = $rule;
				// get all entity ids
				foreach ($rule['approvers'] as $k => $elem) {
					if ($elem['type'] === 'user') {
						$userNames[$elem['entityId']] = null;
					} elseif ($elem['type'] === 'circle' && $circlesEnabled) {
						$circleNames[$elem['entityId']] = null;
					}
				}
				foreach ($rule['requesters'] as $k => $elem) {
					if ($elem['type'] === 'user') {
						$userNames[$elem['entityId']] = null;
					} elseif ($elem['type'] === 'circle' && $circlesEnabled) {
						$circleNames[$elem['entityId']] = null;
					}
				}
			}
		}
		// get display names
		foreach ($userNames as $k => $v) {
			$user = $this->userManager->get($k);
			$userNames[$k] = $user ? $user->getDisplayName() : $k;
		}
		if ($circlesEnabled) {
			$circlesManager = \OC::$server->get(\OCA\Circles\CirclesManager::class);
			$circlesManager->startSuperSession();
			foreach ($circleNames as $k => $v) {
				try {
					$circle = $circlesManager->getCircle($k);
					$circleNames[$k] = $circle->getDisplayName();
				} catch (\OCA\Circles\Exceptions\CircleNotFoundException $e) {
				}
			}
			$circlesManager->stopSession();
		}
		// affect names
		foreach ($userRules as $ruleIndex => $rule) {
			foreach ($rule['approvers'] as $approverIndex => $elem) {
				if ($elem['type'] === 'user') {
					$userRules[$ruleIndex]['approvers'][$approverIndex]['displayName'] = $userNames[$elem['entityId']];
				} elseif ($elem['type'] === 'group') {
					$userRules[$ruleIndex]['approvers'][$approverIndex]['displayName'] = $elem['entityId'];
				} elseif ($elem['type'] === 'circle' && $circlesEnabled) {
					$userRules[$ruleIndex]['approvers'][$approverIndex]['displayName'] = $circleNames[$elem['entityId']];
				}
			}
			foreach ($rule['requesters'] as $requesterIndex => $elem) {
				if ($elem['type'] === 'user') {
					$userRules[$ruleIndex]['requesters'][$requesterIndex]['displayName'] = $userNames[$elem['entityId']];
				} elseif ($elem['type'] === 'group') {
					$userRules[$ruleIndex]['requesters'][$requesterIndex]['displayName'] = $elem['entityId'];
				} elseif ($elem['type'] === 'circle' && $circlesEnabled) {
					$userRules[$ruleIndex]['requesters'][$requesterIndex]['displayName'] = $circleNames[$elem['entityId']];
				}
			}
		}
		return $userRules;
	}

	/**
	 * Check if a user is authorized to approve or request by a given rule
	 *
	 * @param string $userId
	 * @param array $rule
	 * @param string $role
	 * @return bool
	 */
	private function userIsAuthorizedByRule(string $userId, array $rule, string $role = 'approvers'): bool {
		$circlesEnabled = $this->appManager->isEnabledForUser('circles') && class_exists(\OCA\Circles\CirclesManager::class);

		$user = $this->userManager->get($userId);

		$ruleUserIds = array_map(function ($w) {
			return $w['entityId'];
		}, array_filter($rule[$role], function ($w) {
			return $w['type'] === 'user';
		}));

		// if user is in rule's user list
		if (in_array($userId, $ruleUserIds)) {
			return true;
		} else {
			// if user is member of one rule's group list
			$ruleGroupIds = array_map(function ($w) {
				return $w['entityId'];
			}, array_filter($rule[$role], function ($w) {
				return $w['type'] === 'group';
			}));
			foreach ($ruleGroupIds as $groupId) {
				if ($this->groupManager->groupExists($groupId) && $this->groupManager->get($groupId)->inGroup($user)) {
					return true;
				}
			}
			// if user is member of one rule's circle list
			if ($circlesEnabled) {
				$ruleCircleIds = array_map(function ($w) {
					return $w['entityId'];
				}, array_filter($rule[$role], function ($w) {
					return $w['type'] === 'circle';
				}));
				foreach ($ruleCircleIds as $circleId) {
					if ($this->utilsService->isUserInCircle($userId, $circleId)) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * @param string $userId
	 * @param int|null $since
	 * @return array
	 */
	public function getPendingNodes(string $userId, ?int $since = null): array {
		$pendingNodes = [];
		// get pending tags i can approve
		$rules = $this->getBasicUserRules($userId, 'approvers');
		// search files with those tags which i have access to
		$userFolder = $this->root->getUserFolder($userId);
		foreach ($rules as $rule) {
			$pendingTagId = $rule['tagPending'];
			$ruleId = $rule['id'];
			$nodeIdsWithTag = $this->tagObjectMapper->getObjectIdsForTags($pendingTagId, 'files');
			// this actually does not work with tag IDs, only with tag names (not even sure it's about system tags...)
			// $nodes = $userFolder->searchByTag($pendingTagId, $userId);
			foreach ($nodeIdsWithTag as $nodeId) {
				// is the node in the user storage (does the user have access to this node)?
				$nodeInUserStorage = $userFolder->getById((int) $nodeId);
				if (count($nodeInUserStorage) > 0 && !isset($pendingNodes[$nodeId])) {
					$node = $nodeInUserStorage[0];
					$pendingNodes[$nodeId] = [
						'node' => $node,
						'ruleId' => $ruleId,
					];
				}
			}
		}
		// get extra information
		$that = $this;
		$result = array_map(function ($pendingNode) use ($that) {
			$node = $pendingNode['node'];
			$ruleId = $pendingNode['ruleId'];
			return [
				'file_id' => $node->getId(),
				'file_name' => $node->getName(),
				'mimetype' => $node->getMimetype(),
				'activity' => $that->ruleService->getLastAction($node->getId(), $ruleId, Application::STATE_PENDING),

			];
		}, array_values($pendingNodes));

		usort($result, function ($a, $b) {
			if ($a['activity'] === null) {
				if ($b['activity'] === null) {
					return 0;
				} else {
					return 1;
				}
			} elseif ($b['activity'] === null) {
				return -1;
			}
			return ($a['activity']['timestamp'] > $b['activity']['timestamp']) ? -1 : 1;
		});

		return $result;
	}

	/**
	 * Get approval state of a given file for a given user
	 * @param int $fileId
	 * @param string|null $userId
	 * @param bool $userHasAccessChecked whether we already checked if a user has access
	 * @return array state and rule id
	 */
	public function getApprovalState(int $fileId, ?string $userId, bool $userHasAccessChecked = false): array {
		if (is_null($userId) || !($userHasAccessChecked || $this->utilsService->userHasAccessTo($fileId, $userId))) {
			return ['state' => Application::STATE_NOTHING];
		}

		$rules = $this->ruleService->getRules();

		// first check if it's approvable
		foreach ($rules as $id => $rule) {
			try {
				if ($this->tagObjectMapper->haveTag((string) $fileId, 'files', $rule['tagPending'])
					&& $this->userIsAuthorizedByRule($userId, $rule, 'approvers')) {
					return [
						'state' => Application::STATE_APPROVABLE,
						'rule' => $rule,
					];
				}
			} catch (TagNotFoundException $e) {
			}
		}

		// then check pending in priority
		foreach ($rules as $id => $rule) {
			try {
				if ($this->tagObjectMapper->haveTag((string) $fileId, 'files', $rule['tagPending'])) {
					return [
						'state' => Application::STATE_PENDING,
						'rule' => $rule,
					];
				}
			} catch (TagNotFoundException $e) {
			}
		}
		// then rejected
		foreach ($rules as $id => $rule) {
			try {
				if ($this->tagObjectMapper->haveTag((string) $fileId, 'files', $rule['tagRejected'])) {
					return [
						'state' => Application::STATE_REJECTED,
						'rule' => $rule,
					];
				}
			} catch (TagNotFoundException $e) {
			}
		}
		// then approved
		foreach ($rules as $id => $rule) {
			try {
				if ($this->tagObjectMapper->haveTag((string) $fileId, 'files', $rule['tagApproved'])) {
					return [
						'state' => Application::STATE_APPROVED,
						'rule' => $rule,
					];
				}
			} catch (TagNotFoundException $e) {
			}
		}

		return ['state' => Application::STATE_NOTHING];
	}

	/**
	 * Approve a file
	 *
	 * @param int $fileId
	 * @param string|null $userId
	 * @return bool success
	 */
	public function approve(int $fileId, ?string $userId): bool {
		$fileState = $this->getApprovalState($fileId, $userId);
		// if file has pending tag and user is authorized to approve it
		if ($fileState['state'] === Application::STATE_APPROVABLE) {
			$rules = $this->ruleService->getRules();
			foreach ($rules as $ruleId => $rule) {
				try {
					if ($this->tagObjectMapper->haveTag((string) $fileId, 'files', $rule['tagPending'])
						&& $this->userIsAuthorizedByRule($userId, $rule, 'approvers')) {
						$this->tagObjectMapper->assignTags((string) $fileId, 'files', $rule['tagApproved']);
						$this->tagObjectMapper->unassignTags((string) $fileId, 'files', $rule['tagPending']);

						// store activity in our tables
						$this->ruleService->storeAction($fileId, $ruleId, $userId, Application::STATE_APPROVED);

						$this->sendApprovalNotification($fileId, $userId, true);
						$this->activityManager->triggerEvent(
							ActivityManager::APPROVAL_OBJECT_NODE, $fileId,
							ActivityManager::SUBJECT_APPROVED,
							[]
						);
						return true;
					}
				} catch (TagNotFoundException $e) {
				}
			}
		}
		return false;
	}

	/**
	 * Reject a file
	 *
	 * @param int $fileId
	 * @param string|null $userId
	 * @return bool success
	 */
	public function reject(int $fileId, ?string $userId): bool {
		$fileState = $this->getApprovalState($fileId, $userId);
		// if file has pending tag and user is authorized to approve it
		if ($fileState['state'] === Application::STATE_APPROVABLE) {
			$rules = $this->ruleService->getRules();
			foreach ($rules as $ruleId => $rule) {
				try {
					if ($this->tagObjectMapper->haveTag((string) $fileId, 'files', $rule['tagPending'])
						&& $this->userIsAuthorizedByRule($userId, $rule, 'approvers')) {
						$this->tagObjectMapper->assignTags((string) $fileId, 'files', $rule['tagRejected']);
						$this->tagObjectMapper->unassignTags((string) $fileId, 'files', $rule['tagPending']);

						// store activity in our tables
						$this->ruleService->storeAction($fileId, $ruleId, $userId, Application::STATE_REJECTED);

						$this->sendApprovalNotification($fileId, $userId, false);
						$this->activityManager->triggerEvent(
							ActivityManager::APPROVAL_OBJECT_NODE, $fileId,
							ActivityManager::SUBJECT_REJECTED,
							[]
						);
						return true;
					}
				} catch (TagNotFoundException $e) {
				}
			}
		}
		return false;
	}

	/**
	 * @param int $fileId
	 * @param int $ruleId
	 * @param string|null $userId
	 * @param bool $createShares
	 * @return array
	 */
	public function request(int $fileId, int $ruleId, ?string $userId, bool $createShares): array {
		$this->logger->debug('Attempting approval request for fileId: ' . $fileId . ', ruleId: ' . $ruleId . ', userId: ' . $userId . ', createShares: ' . (string)$createShares, ['app' => $this->appName]);

		try {
			$user = $this->userManager->get($userId);
			if (!$user instanceof IUser) {
				$this->logger->warning('User not found for userId: ' . $userId, ['app' => $this->appName]);
				return ['error' => $this->l10n->t('User not found')];
			}

			$userFolder = $this->root->getUserFolder($userId);
			// It's crucial to get the node in the context of the *requesting user* to ensure they have access
			$nodes = $userFolder->getById($fileId);

			if (count($nodes) === 0) {
				// This means the file isn't accessible to the *requesting user* in their own file view.
				// It might exist globally, but they can't "see" it to request approval for it.
				$this->logger->error('File not found or not accessible for user: ' . $userId . ', fileId: ' . $fileId, ['app' => $this->appName]);
				return ['error' => $this->l10n->t('File not found or not accessible by you.')];
			}
			$node = $nodes[0]; // Node in the context of the requester

			$rule = $this->ruleService->getRule($ruleId);
			if ($rule === null) {
				$this->logger->error('Rule not found with id: ' . $ruleId, ['app' => $this->appName]);
				return ['error' => $this->l10n->t('Rule does not exist')];
			}

			if (!$this->userIsAuthorizedByRule($userId, $rule, 'requesters')) {
				$this->logger->warning('User ' . $userId . ' is not authorized to request with rule ' . $ruleId, ['app' => $this->appName]);
				return ['error' => $this->l10n->t('You are not authorized to request with this rule')];
			}
			
			// Check if already pending/approved/rejected for this rule
			// This check should use the node's ID, which is $fileId (or $node->getId())
			if ($this->tagObjectMapper->haveTag((string) $fileId, 'files', $rule['tagPending']) ||
				$this->tagObjectMapper->haveTag((string) $fileId, 'files', $rule['tagApproved']) ||
				$this->tagObjectMapper->haveTag((string) $fileId, 'files', $rule['tagRejected'])) {
				$this->logger->info('File ' . $fileId . ' already has an approval status for rule ' . $ruleId, ['app' => $this->appName]);
				return ['error' => $this->l10n->t('Approval has already been requested or processed for this file with this rule.')];
			}

			// If createShares is true, the actual request logic (tagging, notification) might be deferred
			// or handled after shares are confirmed. For now, the original logic was:
			// if ($createShares) {
			// $this->shareWithApprovers($fileId, $rule, $userId);
			// return []; // Original logic returned early
			// }
			// We'll adjust this: shares are created, then we proceed.

			$this->tagObjectMapper->assignTags((string) $fileId, 'files', $rule['tagPending']);
			
			$this->activityManager->addRequestActivity(
				$fileId,
				$ruleId,
				$node->getName(), // Use the node we fetched
				$userId,
				$user->getDisplayName(),
				$node->getOwner()->getUID() // Owner of the node
			);

			if ($createShares) {
				$sharingOutcome = $this->shareWithApprovers($fileId, $rule, $userId);
				// Log outcome of sharing if needed, e.g., if $sharingOutcome contains error/warning info
				if (isset($sharingOutcome['warning'])) {
					$this->logger->warning('Sharing warning for file ' . $fileId . ', rule ' . $ruleId . ': ' . $sharingOutcome['warning'], ['app' => $this->appName]);
                    // Decide if this warning should halt the process or just be logged.
                    // For now, we continue to send notifications.
				}
                if (isset($sharingOutcome['error'])) {
					$this->logger->error('Sharing error for file ' . $fileId . ', rule ' . $ruleId . ': ' . $sharingOutcome['error'], ['app' => $this->appName]);
                    // If sharing is critical and failed, we might want to return an error here.
                    // return ['error' => 'Failed to create necessary shares: ' . $sharingOutcome['error']];
				}
			}
			
			$this->sendRequestNotification($fileId, $rule, $userId, false);

			return $this->getApprovalState($fileId, $userId, true); // true because we've confirmed user access by getting the node

		} catch (TagNotFoundException $e) {
			$this->logger->error('Tag operation failed for file ' . $fileId . ', rule ' . $ruleId . ': ' . $e->getMessage(), ['app' => $this->appName, 'exception' => $e]);
			return ['error' => $this->l10n->t('Failed to process approval tags: %s', [$e->getMessage()])];
		} catch (\OCP\Files\NotFoundException $e) {
			$this->logger->error('File system error (NotFound) during approval request for file ' . $fileId . ' by user ' . $userId . ': ' . $e->getMessage(), ['app' => $this->appName, 'exception' => $e]);
			return ['error' => $this->l10n->t('File system error: File not found or not accessible. %s', [$e->getMessage()])];
		} catch (\OCP\Files\GenericFileException $e) { // Catch more general file exceptions
			$this->logger->error('Generic file system error during approval request for file ' . $fileId . ' by user ' . $userId . ': ' . $e->getMessage(), ['app' => $this->appName, 'exception' => $e]);
			return ['error' => $this->l10n->t('A file system error occurred: %s', [$e->getMessage()])];
		} catch (\Throwable $e) {
			$this->logger->critical('Unexpected critical error during approval request for file ' . $fileId . ', rule ' . $ruleId . ' by user ' . $userId . ': ' . $e->getMessage() . ' Stack: ' . $e->getTraceAsString(), ['app' => $this->appName, 'exception' => $e]);
			return ['error' => $this->l10n->t('An unexpected server error occurred. Please contact your administrator.')];
			// For debugging, you might return $e->getMessage(), but for production, a generic error is better.
			// return ['error' => 'An unexpected server error occurred: ' . $e->getMessage()];
		}
	}

	/**
	 * @param int $fileId
	 * @param int $ruleId
	 * @param string $requesterUserId
	 * @return array|string[]
	 * @throws \OCP\Files\NotPermittedException
	 * @throws \OC\User\NoUserException
	 */
	public function requestViaTagAssignment(int $fileId, int $ruleId, string $requesterUserId): array {
		$rule = $this->ruleService->getRule($ruleId);
		if (is_null($rule)) {
			return ['error' => 'Rule does not exist'];
		}

		// WARNING we don't actually check if the requester is allowed to request with this rule here
		// because the request was not done with the UI but with manual/auto tag assignment => accept the request anyway
		$this->shareWithApprovers($fileId, $rule, $requesterUserId);
		// store activity in our tables
		$this->ruleService->storeAction($fileId, $ruleId, $requesterUserId, Application::STATE_PENDING);

		// still produce an activity entry for the user who requests
		$this->activityManager->triggerEvent(
			ActivityManager::APPROVAL_OBJECT_NODE, $fileId,
			ActivityManager::SUBJECT_REQUESTED_ORIGIN,
			['origin_user_id' => $requesterUserId]
		);

		// here we don't check if someone can actually approve, because there is nobody to warn
		return [];
	}

	/**
	 * Share file with everybody who can approve with given rule.
	 * This is a simplified revert version.
	 *
	 * @param int $fileId
	 * @param array $rule
	 * @param string $userId The ID of the user initiating the request (requester)
	 * @return array List of shares that were attempted/created (can be empty)
	 */
	private function shareWithApprovers(int $fileId, array $rule, string $userId): array {
		$createdShares = [];
		$this->logger->debug('Reverted_Share_SA: Attempting to share fileId: ' . $fileId . ' for rule: ' . $rule['id'] . ' by user: ' . $userId, ['app' => $this->appName]);

		// First, get the node using the requester's context to ensure they have access to request approval for it.
		$requesterUserFolder = $this->root->getUserFolder($userId);
		$nodeResultsRequester = $requesterUserFolder->getById($fileId);

		if (count($nodeResultsRequester) === 0) {
			$this->logger->error('Reverted_Share_SA: Node with fileId ' . $fileId . ' not found or not accessible for requesting user ' . $userId, ['app' => $this->appName]);
			return ['error' => 'File not found by requester, cannot initiate sharing for approval.'];
		}
		$nodeForSharing = $nodeResultsRequester[0]; // This is the node we intend to share.
		$fileOwnerId = $nodeForSharing->getOwner()->getUID();

		// It's generally best practice for shares to be created by the actual owner of the file.
		// If the requester is not the owner, get the node again from the owner's perspective.
		if ($userId !== $fileOwnerId) {
			$ownerUserFolder = $this->root->getUserFolder($fileOwnerId);
			$nodeResultsOwner = $ownerUserFolder->getById($fileId);
			if (count($nodeResultsOwner) === 0) {
				$this->logger->error('Reverted_Share_SA: Node with fileId ' . $fileId . ' not found in owner \'' . $fileOwnerId . '\'s context. Cannot create share as owner.', ['app' => $this->appName]);
				// Fallback: attempt to share the node instance we got from the requester. This might fail if permissions are insufficient.
				$this->logger->warning('Reverted_Share_SA: Proceeding to share node from requester\'s context due to owner context failure.', ['app' => $this->appName]);
			} else {
				$nodeForSharing = $nodeResultsOwner[0]; // Prefer the owner's node instance for sharing.
			}
		}

		$label = $this->l10n->t('Please check my approval request');

		foreach ($rule['approvers'] as $approverDetails) {
			$approverEntityId = $approverDetails['entityId'];
			$approverType = $approverDetails['type'];
			$shareType = null;

			if ($approverType === 'user') {
				$shareType = IShare::TYPE_USER;
			} elseif ($approverType === 'group' && $this->shareManager->allowGroupSharing()) {
				$shareType = IShare::TYPE_GROUP;
			} elseif ($approverType === 'circle' && $this->appManager->isEnabledForUser('circles') && class_exists(\\OCA\\Circles\\CirclesManager::class)) {
				$shareType = IShare::TYPE_CIRCLE;
			}

			if ($shareType !== null) {
				// Basic check: don't re-share to a user if they already have access.
				// This can be made more sophisticated if needed.
				if ($shareType === IShare::TYPE_USER && $this->utilsService->userHasAccessTo($fileId, $approverEntityId)) {
					$this->logger->info('Reverted_Share_SA: User ' . $approverEntityId . ' already has access to fileId ' . $fileId . '. Skipping duplicate share creation.', ['app' => $this->appName]);
					// We might still want to record that a share attempt was made or would have been made.
					// For now, just skip actual share creation to be safe.
					// $createdShares[] = $approverDetails; // Add to indicate it was processed, even if not shared anew.
					continue; // Skip to next approver
				}

				// Call the simplified createShare from UtilsService, using $fileOwnerId as the one performing the share action.
				if ($this->utilsService->createShare($nodeForSharing, $shareType, $approverEntityId, $fileOwnerId, $label)) {
					$createdShares[] = $approverDetails;
					$this->logger->debug('Reverted_Share_SA: Successfully created share for ' . $approverType . ' ' . $approverEntityId . ' for fileId ' . $fileId, ['app' => $this->appName]);
				} else {
					$this->logger->warning('Reverted_Share_SA: Failed to create share for ' . $approverType . ' ' . $approverEntityId . ' for fileId ' . $fileId, ['app' => $this->appName]);
				}
			}
		}
		return $createdShares;
	}

	/**
	 * Send approval notifications for a given file to all users having access to it.
	 *
	 * @param int $fileId
	 * @param string|null $approverId
	 * @param bool $approved
	 * @return void
	 */
	private function sendApprovalNotification(int $fileId, ?string $approverId, bool $approved): void {
		$paramsByUser = [];
		$root = $this->root;
		// notification for eveyone having access except the one approving/rejecting
		$this->userManager->callForSeenUsers(function (IUser $user) use ($root, $fileId, $approverId, &$paramsByUser) {
			$thisUserId = $user->getUID();
			if ($thisUserId !== $approverId) {
				$userFolder = $root->getUserFolder($thisUserId);
				$found = $userFolder->getById($fileId);
				if (count($found) > 0) {
					$node = $found[0];
					$path = $userFolder->getRelativePath($node->getPath());
					$type = $node->getType() === FileInfo::TYPE_FILE
						? 'file'
						: 'folder';
					$paramsByUser[$thisUserId] = [
						'type' => $type,
						'fileId' => $fileId,
						'fileName' => $node->getName(),
						'relativePath' => $path,
						'approverId' => $approverId,
					];
				}
			}
		});

		foreach ($paramsByUser as $userId => $params) {
			$manager = $this->notificationManager;
			$notification = $manager->createNotification();

			$subject = $approved ? 'approved' : 'rejected';
			$notification->setApp(Application::APP_ID)
				->setUser($userId)
				->setDateTime(new DateTime())
				->setObject('dum', 'dum')
				->setSubject($subject, $params);

			$manager->notify($notification);
		}
	}

	/**
	 * Get ids of users authorized to approve or request by a given rule
	 *
	 * @param array $rule
	 * @param string $role
	 * @return array userId list
	 */
	public function getRuleAuthorizedUserIds(array $rule, string $role = 'approvers'): array {
		$circlesEnabled = $this->appManager->isEnabledForUser('circles') && class_exists(\OCA\Circles\CirclesManager::class);
		if ($circlesEnabled) {
			$circlesManager = \OC::$server->get(\OCA\Circles\CirclesManager::class);
			$circlesManager->startSuperSession();
		}

		$ruleUserIds = [];
		foreach ($rule[$role] as $approver) {
			if ($approver['type'] === 'user') {
				if (!in_array($approver['entityId'], $ruleUserIds)) {
					$ruleUserIds[] = $approver['entityId'];
				}
			} elseif ($approver['type'] === 'group') {
				$groupId = $approver['entityId'];
				if ($this->groupManager->groupExists($groupId)) {
					$users = $this->groupManager->get($groupId)->getUsers();
					foreach ($users as $user) {
						if ($user instanceof IUser && !in_array($user->getUID(), $ruleUserIds)) {
							$ruleUserIds[] = $user->getUID();
						}
					}
				}
			} elseif ($circlesEnabled && $approver['type'] === 'circle') {
				$circleId = $approver['entityId'];
				try {
					$circle = $circlesManager->getCircle($circleId);
					$circleMembers = $circle->getMembers();
					foreach ($circleMembers as $member) {
						// only consider users
						if ($member->getUserType() !== 1) {
							continue;
						}
						$memberUserId = $member->getUserId();
						if (!in_array($memberUserId, $ruleUserIds)) {
							$ruleUserIds[] = $memberUserId;
						}
					}
				} catch (\OCA\Circles\Exceptions\CircleNotFoundException $e) {
				}
			}
		}

		if ($circlesEnabled) {
			$circlesManager->stopSession();
		}
		return $ruleUserIds;
	}

	/**
	 * Called when a tag is assigned
	 *
	 * @param int $fileId
	 * @param array $tags
	 * @return void
	 */
	public function handleTagAssignmentEvent(int $fileId, array $tags): void {
		// which rule is involved?
		$ruleInvolded = null;
		$rules = $this->ruleService->getRules();
		foreach ($rules as $id => $rule) {
			// rule matches tags
			if (in_array($rule['tagPending'], $tags)) {
				$ruleInvolded = $rule;
				break;
			}
		}
		if (is_null($ruleInvolded)) {
			$this->logger->debug(
				'Could not request approval of file ' . $fileId . ': no rule found for tags ' . implode(',', $tags) . '.',
				['app' => Application::APP_ID]
			);
			return;
		}
		// search our activities to see if we know who made the request
		$activity = $this->ruleService->getLastAction($fileId, $ruleInvolded['id'], Application::STATE_PENDING);
		// if there is no activity, the tag was assigned manually (or via auto-tagging flows)
		// => perform the request here (share, store action and trigger activity event)
		if ($activity === null) {
			$found = $this->root->getById($fileId);
			if (count($found) > 0) {
				$node = $found[0];
			} else {
				$this->logger->error('Could not request approval of file ' . $fileId . ': file not found.', ['app' => Application::APP_ID]);
				return;
			}
			// the requester user ID is the current user or else the file owner
			$requesterUserId = $this->userId ?? $node->getOwner()->getUID();
			$requestResult = $this->requestViaTagAssignment($fileId, $ruleInvolded['id'], $requesterUserId);
			if (isset($requestResult['error'])) {
				$this->logger->error('Approval request error: ' . $requestResult['error'] . '.', ['app' => Application::APP_ID]);
				return;
			}
			$this->sendRequestNotification($fileId, $ruleInvolded, $requesterUserId, false);
		} else {
			// it was request via the approval interface, nothing more to do
			$requesterUserId = $activity['userId'];
			$this->sendRequestNotification($fileId, $ruleInvolded, $requesterUserId, true);
		}
	}

	/**
	 * Send notifications when a file approval is requested
	 * Send it to all users who are authorized to approve it
	 *
	 * @param int $fileId
	 * @param array $rule
	 * @param string $requestUserId
	 * @return void
	 */
	public function sendRequestNotification(int $fileId, array $rule, string $requestUserId, bool $checkAccess): void {
		// find users involved in rules matching tags
		$rulesUserIds = [];
		$thisRuleUserIds = $this->getRuleAuthorizedUserIds($rule, 'approvers');
		foreach ($thisRuleUserIds as $userId) {
			if (!in_array($userId, $rulesUserIds)) {
				$rulesUserIds[] = $userId;
			}
		}
		// create activity (which deals with access checks)
		$this->activityManager->triggerEvent(
			ActivityManager::APPROVAL_OBJECT_NODE, $fileId,
			ActivityManager::SUBJECT_MANUALLY_REQUESTED,
			['users' => $thisRuleUserIds, 'who' => $requestUserId]
		);

		$paramsByUser = [];
		$root = $this->root;
		if ($checkAccess) {
			// only notify users having access to the file
			foreach ($rulesUserIds as $userId) {
				$userFolder = $root->getUserFolder($userId);
				$found = $userFolder->getById($fileId);
				if (count($found) > 0) {
					$node = $found[0];
					$path = $userFolder->getRelativePath($node->getPath());
					$type = $node->getType() === FileInfo::TYPE_FILE
						? 'file'
						: 'folder';
					$paramsByUser[$userId] = [
						'type' => $type,
						'fileId' => $fileId,
						'fileName' => $node->getName(),
						'relativePath' => $path,
					];
				}
			}
		} else {
			// we don't check if users have access to the file because they might not have yet (share is not effective yet)
			// => notify every approver
			foreach ($rulesUserIds as $userId) {
				$found = $root->getById($fileId);
				if (count($found) > 0) {
					$node = $found[0];
					// we don't know the path in user storage
					$path = '';
					$type = $node->getType() === FileInfo::TYPE_FILE
						? 'file'
						: 'folder';
					$paramsByUser[$userId] = [
						'type' => $type,
						'fileId' => $fileId,
						'fileName' => $node->getName(),
						'relativePath' => $path,
					];
				}
			}
		}

		// actually send the notifications
		foreach ($paramsByUser as $userId => $params) {
			$manager = $this->notificationManager;
			$notification = $manager->createNotification();

			$subject = 'manual_request';
			$params['requesterId'] = $requestUserId;
			$notification->setApp(Application::APP_ID)
				->setUser($userId)
				->setDateTime(new DateTime())
				->setObject('dum', 'dum')
				->setSubject($subject, $params);

			$manager->notify($notification);
		}
	}

	/**
	 * Get approval state as a WebDav attribute
	 *
	 * @param PropFind $propFind
	 * @param INode $node
	 * @return void
	 */
	public function propFind(PropFind $propFind, INode $node): void {
		if (!$node instanceof SabreNode) {
			return;
		}
		$nodeId = $node->getId();

		$propFind->handle(
			Application::DAV_PROPERTY_APPROVAL_STATE, function () use ($nodeId) {
				$state = $this->getApprovalState($nodeId, $this->userId, true);
				return $state['state'];
			}
		);
	}
}
