<!--
  - SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDashboardWidget :title="title">
		<template #actions>
			<p>TEST ACTION SLOT</p>
		</template>

		<template #default>
			<div>
				<p>TEST MAIN SLOT - PENDING COUNT: {{ pendingFiles.length }}</p>
				<p>LOADING STATE: {{ loading }}</p>
			</div>
		</template>
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
	props: {
		title: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			// title: 'TEST TITLE - Pending Approvals', // Title now comes from props
			// icon: ApprovalIcon, // Icon prop commented out for testing
			pendingFiles: [],
			loading: false, // Initialize as false
		}
	},
	// Temporarily comment out the entire mounted hook - NOW UNCOMMENTED
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

			if (response.data && response.data.ocs && response.data.ocs.data) {
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
				console.warn('[Approval App Dashboard] API response structure is not as expected:', response.data)
				this.pendingFiles = []
				showError(t('approval', 'Could not fetch pending approvals: Invalid API response'))
			}
		} catch (error) {
			// eslint-disable-next-line no-console
			console.error('[Approval App Dashboard] Error fetching pending files:', error)
			showError(t('approval', 'Could not fetch pending approvals'))
		} finally {
			this.loading = false
			// eslint-disable-next-line no-console
			console.log('[Approval App Dashboard] Loading set to false. Current pendingFiles length:', this.pendingFiles.length)
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

/* Add any specific styles for your widget content here */
.widget-content ul {
	list-style: none;
	padding-inline-start: 0;
}

.widget-content li {
	margin-bottom: 8px;
}
</style>
