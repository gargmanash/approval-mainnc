<!--
  - SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDashboardWidget :title="title">
		<template #actions>
			<p>TEST ACTION SLOT</p>
		</template>

		<div>
			<p>TEST MAIN SLOT - PENDING COUNT: {{ pendingFiles.length }}</p>
			<p>LOADING STATE: {{ loading }}</p>
		</div>

	</NcDashboardWidget>
</template>

<script>
import { NcDashboardWidget } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'

// import ApprovalIcon from '../components/icons/GroupIcon.vue' // Icon import commented out for testing

export default {
	name: 'DashboardPending',
	components: {
		NcDashboardWidget,
	},
	data() {
		return {
			title: 'TEST TITLE - Pending Approvals', // Static title, t() function removed for testing
			// icon: ApprovalIcon, // Icon prop commented out for testing
			pendingFiles: [],
			loading: true,
		}
	},
	async mounted() {
		try {
			// eslint-disable-next-line no-console
			console.log('[Approval App Dashboard] Fetching pending files...')
			const response = await axios.get(generateUrl('/ocs/v2.php/apps/approval/api/v1/pendings'))

			// eslint-disable-next-line no-console
			console.log('[Approval App Dashboard] Raw API Response object:', response)

			// eslint-disable-next-line no-console
			console.log('[Approval App Dashboard] response.data:', response.data)
			// eslint-disable-next-line no-console
			console.log('[Approval App Dashboard] response.data (stringified):', JSON.stringify(response.data))

			if (response.data && response.data.ocs) {
				// eslint-disable-next-line no-console
				console.log('[Approval App Dashboard] response.data.ocs:', response.data.ocs)
				// eslint-disable-next-line no-console
				console.log('[Approval App Dashboard] response.data.ocs (stringified):', JSON.stringify(response.data.ocs))

				const pendingData = response.data.ocs.data
				// eslint-disable-next-line no-console
				console.log('[Approval App Dashboard] Extracted pendingData (response.data.ocs.data):', pendingData)
				// eslint-disable-next-line no-console
				console.log('[Approval App Dashboard] Extracted pendingData (stringified):', JSON.stringify(pendingData))

				this.pendingFiles = pendingData
				// eslint-disable-next-line no-console
				console.log('[Approval App Dashboard] this.pendingFiles after assignment:', this.pendingFiles)
			} else {
				// eslint-disable-next-line no-console
				console.error('[Approval App Dashboard] API response.data or response.data.ocs is missing. Response data:', response.data)
			}

		} catch (e) {
			// eslint-disable-next-line no-console
			console.error('[Approval App Dashboard] Error loading pending files:', e)
			showError(t('approval', 'Could not load pending files')) // t() is still used here, but this is only on error
		} finally {
			this.loading = false
			// eslint-disable-next-line no-console
			console.log('[Approval App Dashboard] Loading set to false. Current pendingFiles length:', this.pendingFiles ? this.pendingFiles.length : 'undefined')
		}
	},
	methods: {
		openApprovalCenter() {
			window.location.href = generateUrl('/apps/approval/approval-center')
		},
	},
}
</script>

<style scoped lang="scss">
.empty-content {
	text-align: center;
	padding: 20px;

	.empty-content-icon {
		margin-bottom: 10px;
	}
}

/* .pending-files-summary {
	list-style: none;
	padding-left: 0;
	li {
		padding: 5px 0;
	}
} */
</style>
