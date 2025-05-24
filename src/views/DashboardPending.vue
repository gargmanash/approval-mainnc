<!--
  - SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDashboardWidget :title="title" :loading="loading">
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
			{{ console.log('Items in template:', items) }}
			<NcDashboardWidgetItem
				v-for="item in items"
				:key="item.file_id"
				:title="item.file_name"
				:subtitle="item.subtitle"
				:link="item.link"
				:icon="item.iconUrl"
				:datetime="item.activity_timestamp ? new Date(item.activity_timestamp * 1000) : null" />
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
/* eslint-disable no-console */
import { NcButton, NcDashboardWidget, NcDashboardWidgetItem, NcEmptyContent, NcIconSvgWrapper, useFormatDateTime } from '@nextcloud/vue'
import { generateUrl, imagePath } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'

export default {
	name: 'DashboardPending',
	components: {
		NcButton,
		NcDashboardWidget,
		NcDashboardWidgetItem,
		NcEmptyContent,
		NcIconSvgWrapper,
	},
	props: {
		title: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			items: [],
			loading: true,
			relativeDateFormatter: null,
		}
	},
	computed: {
		openApprovalCenterUrl() {
			return generateUrl('/apps/approval/')
		},
	},
	created() {
		const formatter = useFormatDateTime()
		this.relativeDateFormatter = formatter.formatRelativeDateTime || formatter.formatDate || formatter.formatDateTime
	},
	async mounted() {
		this.loading = true
		try {
			const response = await axios.get(generateUrl('/ocs/v2.php/apps/approval/api/v1/pendings'))
			this.items = response.data.ocs.data.map(pendingItem => {
				const activity = pendingItem.activity || {}
				const requesterName = activity.userName || this.t('approval', 'Unknown user')

				// Construct subtitle
				let subtitle = this.t('approval', 'Requested by {user}', { user: requesterName })
				if (activity.timestamp && this.relativeDateFormatter) {
					subtitle += ` - ${this.relativeDateFormatter(activity.timestamp * 1000)}`
				}

				// Construct icon URL
				let iconUrl = imagePath('core', 'actions/details') // Default icon
				if (pendingItem.mimetype && pendingItem.mimetype.startsWith('image/')) {
					iconUrl = generateUrl(`/apps/files/api/v1/thumbnail/${pendingItem.file_id}/32/32`)
				} else if (pendingItem.mimetype) {
					iconUrl = imagePath('core', `filetypes/${pendingItem.mimetype.replace('/', '-')}.svg`)
				}

				return {
					file_id: pendingItem.file_id,
					file_name: pendingItem.file_name || this.t('approval', 'Unknown file'),
					assignee: pendingItem.assignee?.displayName || this.t('approval', 'Unknown user'),
					activity_timestamp: activity.timestamp || Math.floor(Date.now() / 1000),
					mimetype: pendingItem.mimetype,
					link: generateUrl(`/apps/files/files/${pendingItem.file_id}`),
					iconUrl,
					subtitle,
				}
			})
			console.log('Generated items in mounted:', this.items)
		} catch (e) {
			console.error(e)
			showError(this.t('approval', 'Could not load pending approvals'))
		} finally {
			this.loading = false
		}
	},
	methods: {
		t(scope, text, params) {
			return t(scope, text, params)
		},
		openApprovalCenter() {
			window.location.href = this.openApprovalCenterUrl
		},
	},
}
</script>

<style scoped lang="scss">
.empty-content {
	display: flex;
	justify-content: center;
	align-items: center;
	height: 100%;
	flex-direction: column;
}

.widget-content ul {
	list-style: none;
	padding-inline-start: 0;
}

.widget-content li {
	margin-bottom: 8px;
}
</style>
