<?php
/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Approval\Dashboard;

use OCA\Approval\AppInfo\Application;
use OCA\Approval\Service\ApprovalService;
use OCP\Dashboard\IAPIWidget;
use OCP\Dashboard\IWidget;
use OCP\Dashboard\Model\WidgetItem;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;

class ApprovalPendingWidget implements IWidget, IAPIWidget {

	public function __construct(
		private IL10N $l10n,
		private ApprovalService $approvalService,
		private IURLGenerator $urlGenerator
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function getId(): string {
		return 'approval_pending';
	}

	/**
	 * @inheritDoc
	 */
	public function getTitle(): string {
		return $this->l10n->t('Pending approvals');
	}

	/**
	 * @inheritDoc
	 */
	public function getOrder(): int {
		return 10;
	}

	/**
	 * @inheritDoc
	 */
	public function getIconClass(): string {
		return 'icon-approval';
	}

	/**
	 * @inheritDoc
	 */
	public function getUrl(): ?string {
		return $this->urlGenerator->linkToRoute(Application::APP_ID . '.Page.approvalCenter');
	}

	/**
	 * @inheritDoc
	 */
	public function load(): void {
		Util::addScript(Application::APP_ID, 'approval-dashboardPending');
		Util::addStyle(Application::APP_ID, 'dashboard');
	}

	/**
	 * @inheritDoc
	 */
	public function getItems(string $userId, ?string $since = null, int $limit = 7): array {
		$pendingNodesData = $this->approvalService->getPendingNodes($userId, $since);
		$widgetItems = [];

		$limitedData = array_slice($pendingNodesData, 0, $limit);

		foreach ($limitedData as $item) {
			$nodeId = $item['file_id'];
			$fileName = $item['file_name'];
			$activity = $item['activity'];
			$requesterName = $activity['userName'] ?? $this->l10n->t('Unknown user');
			$timestamp = $activity['timestamp'] ?? time();

			$subtitle = $this->l10n->t('Requested by %1$s on %2$s', [$requesterName, date('Y-m-d H:i', (int)$timestamp)]);
			$fileViewUrl = $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.Page.approvalCenter', ['file_id' => $nodeId]);
			
			$mimeTypeBase = strtok($item['mimetype'], '/');
			$iconUrl = $this->urlGenerator->imagePath('core', 'filetypes/' . $mimeTypeBase . '.svg');
			if (strpos($iconUrl, 'filetypes/.') !== false || strpos($iconUrl, 'filetypes/.svg') !== false) {
			    $iconUrl = $this->urlGenerator->imagePath(Application::APP_ID, 'app.svg');
			}

			$widgetItems[] = new WidgetItem(
				(string)$nodeId,
				$fileName,
				$subtitle,
				$fileViewUrl,
				$this->getIconClass(),
				$iconUrl,
				(new \DateTime('@' . $timestamp))->format(DateTime::ATOM)
			);
		}
		return $widgetItems;
	}

	/**
	 * @inheritDoc
	 */
	public function getItemApiVersion(): int {
		return 1;
	}
}
