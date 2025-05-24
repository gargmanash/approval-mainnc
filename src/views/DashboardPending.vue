<!--
  - SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDashboardWidget :title="title" :loading="loading">
		<IconApproval v-if="false" />
		<template #actions>
			<!-- Actions slot can be used for buttons like 'View all' or refresh -->
			<NcButton
				v-if="items.length > 0 || !loading"
				:aria-label="t('approval', 'View all pending approvals')"
				:href="openApprovalCenterUrl"
				type="tertiary">
				{{ t('approval', 'View all') }}
			</NcButton>
		</template>

		<template #default>
			<NcDashboardWidgetItem
				v-for="item in items"
				:key="item.file_id"
				:title="item.file_name"
				:subtitle="item.subtitle"
				:link="item.link"
				:icon="item.iconUrl"
				:datetime="item.datetime ? new Date(item.datetime) : null" />
		</template>

		<template #empty>
			<div v-if="!loading && items.length === 0" class="empty-content">
				<NcEmptyContent
					:title="t('approval', 'No pending approvals')"
					:description="t('approval', 'You have no files awaiting your approval at the moment.')">
					<template #icon>
						<NcIconSvgWrapper :icon="iconApprovalComponent" :size="64" />
					</template>
				</NcEmptyContent>
			</div>
		</template>
	</NcDashboardWidget>
</template>

<script>
import { NcButton, NcDashboardWidget, NcDashboardWidgetItem, NcEmptyContent, NcIconSvgWrapper } from '@nextcloud/vue'
import { generateUrl, imagePath } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { formatRelativeDate } from '@nextcloud/vue'

import IconApprovalComponent from '../components/icons/GroupIcon.vue'

export default {
	name: 'DashboardPending',
	components: {
		NcButton,
		NcDashboardWidget,
		NcDashboardWidgetItem,
		NcEmptyContent,
		NcIconSvgWrapper,
		IconApproval: IconApprovalComponent,
	},
	props: {
		title: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			iconApprovalComponent: IconApprovalComponent,
			items: [],
			loading: true,
		}
	},
	computed: {
		openApprovalCenterUrl() {
			return generateUrl('/apps/approval/approval-center')
		},
	},
	async mounted() {
		await this.fetchPendingApprovals()
	},
	methods: {
		async fetchPendingApprovals() {
			this.loading = true
			try {
				const response = await axios.get(generateUrl('/ocs/v2.php/apps/approval/api/v1/pendings'))
				if (response.data && response.data.ocs && Array.isArray(response.data.ocs.data)) {
					this.items = response.data.ocs.data.map(pendingItem => {
						const activity = pendingItem.activity || {}
						const requesterName = activity.userName || this.t('approval', 'Unknown user')
						const timestamp = activity.timestamp || Math.floor(Date.now() / 1000)
						
						// Construct subtitle
						let subtitle = this.t('approval', 'Requested by {user}', { user: requesterName })
						if (activity.timestamp) {
							subtitle += ` - ${formatRelativeDate(activity.timestamp * 1000)}`
						}

						// Construct icon URL (similar to PHP widget logic)
						let iconUrlValue = imagePath('core', `filetypes/${pendingItem.mimetype.split('/')[0]}.svg`)
						try {
							if (iconUrlValue.includes('/.') || !pendingItem.mimetype) {
								iconUrlValue = imagePath('approval', 'app.svg')
							}
						} catch (e) {
							iconUrlValue = imagePath('approval', 'app.svg')
						}

						const linkValue = generateUrl(`/apps/approval/approval-center?file_id=${pendingItem.file_id}`)

						return {
							file_id: pendingItem.file_id,
							file_name: pendingItem.file_name,
							subtitle,
							link: linkValue,
							iconUrl: iconUrlValue,
							datetime: timestamp * 1000,
						}
					})
				} else {
					this.items = []
					showError(this.t('approval', 'Could not fetch pending approvals: Invalid API response structure'))
				}
			} catch (e) {
				console.error('Error fetching pending approvals:', e)
				showError(this.t('approval', 'Could not fetch pending approvals'))
				this.items = []
			} finally {
				this.loading = false
			}
		},
		openApprovalCenter() {
			window.location.href = this.openApprovalCenterUrl
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
