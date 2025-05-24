<?php
/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Approval\Dashboard;

use OCA\Approval\AppInfo\Application;
use OCP\Dashboard\IWidget;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;

class ApprovalPendingWidget implements IWidget {

	public function __construct(
		private IL10N $l10n,
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
}
