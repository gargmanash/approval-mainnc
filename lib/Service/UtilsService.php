<?php

/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Approval\Service;

use Exception;
use OCA\Approval\AppInfo\Application;
use OCP\Constants;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;

use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ICrypto;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\TagAlreadyExistsException;
use OCP\SystemTag\TagNotFoundException;

class UtilsService {

	/**
	 * Service providing storage, circles and tags tools
	 */
	public function __construct(
		string $appName,
		private IUserManager $userManager,
		private IShareManager $shareManager,
		private IRootFolder $root,
		private ISystemTagManager $tagManager,
		private IConfig $config,
		private ICrypto $crypto
	) {
	}

	/**
	 * Get decrypted app value
	 *
	 * @return string
	 * @throws Exception
	 */
	public function getEncryptedAppValue(string $key): string {
		$storedValue = $this->config->getAppValue(Application::APP_ID, $key);
		if ($storedValue === '') {
			return '';
		}
		return $this->crypto->decrypt($storedValue);
	}

	/**
	 * Store encrypted client secret
	 *
	 * @param string $value
	 * @return void
	 */
	public function setEncryptedAppValue(string $key, string $value): void {
		if ($value === '') {
			$this->config->setAppValue(Application::APP_ID, $key, '');
		} else {
			$encryptedClientSecret = $this->crypto->encrypt($value);
			$this->config->setAppValue(Application::APP_ID, $key, $encryptedClientSecret);
		}
	}

	/**
	 * Create one share
	 *
	 * @param Node $node
	 * @param int $type
	 * @param string $sharedWith
	 * @param string $sharedBy
	 * @param string $label
	 * @param string|null $originalRelativePath
	 * @return bool success
	 */
	public function createShare(Node $node, int $type, string $sharedWith, string $sharedBy, string $label, ?string $originalRelativePath = null): bool {
		$share = $this->shareManager->newShare();
		$share->setNode($node)
			->setPermissions(Constants::PERMISSION_READ)
			->setSharedWith($sharedWith)
			->setShareType($type)
			->setSharedBy($sharedBy)
			->setMailSend(false)
			->setExpirationDate(null);

		// DO NOT attempt $share->setTargetPath(...) here as it's an invalid method for a new share object.
		// The system will place the share in the recipient's 'Shared' folder by default.
		// $originalRelativePath is kept as a parameter in case future Nextcloud APIs allow influencing this,
		// or for logging/notification purposes. For now, it does not set the hierarchical path.

		try {
			$share = $this->shareManager->createShare($share);
			// After creation, update the share with label, note, and status
			$share->setLabel($label)
				->setNote($label) // You could potentially put $originalRelativePath in the note for user info
				->setMailSend(false) // mail send status can also be set on an existing share
				->setStatus(IShare::STATUS_ACCEPTED);
			$this->shareManager->updateShare($share);
			return true;
		} catch (Exception $e) {
			// Consider logging the exception message: $e->getMessage()
			// e.g., $this->logger->error('Failed to create or update share: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			// Ensure you have a logger injected if you use $this->logger
			return false;
		}
	}

	/**
	 * Check if a user is in a given circle
	 *
	 * @param string $userId
	 * @param string $circleId
	 * @return bool
	 */
	public function isUserInCircle(string $userId, string $circleId): bool {
		$circlesManager = \OC::$server->get(\OCA\Circles\CirclesManager::class);
		$circlesManager->startSuperSession();
		try {
			$circle = $circlesManager->getCircle($circleId);
		} catch (\OCA\Circles\Exceptions\CircleNotFoundException $e) {
			$circlesManager->stopSession();
			return false;
		}
		// is the circle owner
		$owner = $circle->getOwner();
		// the owner is also a member so this might be useless...
		if ($owner->getUserType() === 1 && $owner->getUserId() === $userId) {
			$circlesManager->stopSession();
			return true;
		} else {
			$members = $circle->getMembers();
			foreach ($members as $m) {
				// is member of this circle
				if ($m->getUserType() === 1 && $m->getUserId() === $userId) {
					$circlesManager->stopSession();
					return true;
				}
			}
		}
		$circlesManager->stopSession();
		return false;
	}

	/**
	 * Check if user has access to a given file
	 *
	 * @param int $fileId
	 * @param string|null $userId
	 * @return bool
	 */
	public function userHasAccessTo(int $fileId, ?string $userId): bool {
		$user = $this->userManager->get($userId);
		if ($user instanceof IUser) {
			$userFolder = $this->root->getUserFolder($userId);
			$found = $userFolder->getById($fileId);
			return count($found) > 0;
		}
		return false;
	}

	/**
	 * @param string $name of the new tag
	 * @return array
	 */
	public function createTag(string $name): array {
		try {
			$tag = $this->tagManager->createTag($name, false, false);
			return ['id' => $tag->getId()];
		} catch (TagAlreadyExistsException $e) {
			return ['error' => 'Tag already exists'];
		}
	}

	/**
	 * @param int $id of the tag to delete
	 * @return array
	 */
	public function deleteTag(int $id): array {
		try {
			$this->tagManager->deleteTags((string) $id);
			return ['success' => true];
		} catch (TagNotFoundException $e) {
			return ['error' => 'Tag not found'];
		}
	}

	/**
	 * Ensures a given folder hierarchy exists for a user.
	 *
	 * @param string $userId The ID of the user.
	 * @param string $relativePath The desired relative path from the user's root (e.g., "Shared/MySubFolder/AnotherLevel").
	 * @return \\OCP\\Files\\Folder|null The final folder node if successful, null otherwise.
	 */
	public function ensureFolderHierarchy(string $userId, string $relativePath): ?\\OCP\\Files\\Folder {
		try {
			$userFolder = $this->root->getUserFolder($userId);
			if (!$userFolder) {
				// Log error: Unable to get user folder for $userId
				return null;
			}

			$currentPath = '';
			$parts = explode('/', trim($relativePath, '/'));
			$folder = $userFolder;

			foreach ($parts as $part) {
				if (empty($part)) {
					continue;
				}
				$currentPath .= ($currentPath === '' ? '' : '/') . $part;
				$node = $userFolder->get($currentPath);
				if ($node instanceof \\OCP\\Files\\Folder) {
					$folder = $node;
				} elseif ($node === null) { // Path does not exist, try to create folder
					$folder = $userFolder->newFolder($currentPath);
				} else { // Path exists but is not a folder
					// Log error: Path $currentPath exists but is not a folder for user $userId
					return null;
				}
			}
			return $folder; // Returns the deepest folder in the hierarchy
		} catch (\\OCP\\Files\\NotPermittedException $e) {
			// Log error: Permission denied creating folder hierarchy for $userId at $relativePath
			return null;
		} catch (\\OCP\\Files\\StorageNotAvailableException $e) {
			// Log error: Storage not available for $userId
			return null;
		} catch (Exception $e) {
			// Log general error: $e->getMessage()
			return null;
		}
	}
}
