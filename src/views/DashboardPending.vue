<!--
  - SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDashboardWidget :icon="icon" :title="title" :loading="loading">
		<template #actions>
			<NcButton type="primary" @click="openApprovalCenter">
				{{ t('approval', 'Open Approval Center') }}
			</NcButton>
		</template>
		<div v-if="pendingFiles.length === 0 && !loading" class="empty-content">
			<div class="empty-content-icon">
				<CheckIcon :size="128" />
			</div>
			<div class="empty-content-text">
				{{ t('approval', 'No files pending your approval') }}
			</div>
		</div>
		<div v-else-if="!loading">
			<p>{{ t('approval', 'You have {count} file(s) pending your approval.', { count: pendingFiles.length }) }}</p>
			<p>{{ t('approval', 'Go to the Approval Center for a detailed view and KPIs.') }}</p>
			<!-- Optional: Display a few top pending files here as a quick preview -->
			<!-- <ul class="pending-files-summary">
				<li v-for="file in pendingFiles.slice(0, 3)" :key="file.id">
					{{ file.name }} ({{ file.path }})
				</li>
			</ul> -->
		</div>
	</NcDashboardWidget>
</template>

<script>
import { NcDashboardWidget, NcButton } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'

import ApprovalIcon from '../components/icons/GroupIcon.vue' // Assuming this is the desired app icon
import CheckIcon from 'vue-material-design-icons/Check.vue'

export default {
	name: 'DashboardPending',
	components: {
		NcDashboardWidget,
		NcButton,
		CheckIcon,
	},
	data() {
		return {
			title: t('approval', 'Pending Approvals'),
			icon: ApprovalIcon,
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
			console.log('[Approval App Dashboard] API Response:', response)
			const pendingData = response.data.ocs.data
			// eslint-disable-next-line no-console
			console.log('[Approval App Dashboard] Extracted pending data:', pendingData)
			this.pendingFiles = pendingData
			// eslint-disable-next-line no-console
			console.log('[Approval App Dashboard] this.pendingFiles after assignment:', this.pendingFiles)
		} catch (e) {
			// eslint-disable-next-line no-console
			console.error('[Approval App Dashboard] Error loading pending files:', e)
			showError(t('approval', 'Could not load pending files'))
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
</style>
