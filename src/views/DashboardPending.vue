<!--
  - SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDashboardWidget
		:title="title"
		:items="items"
		:loading="loading"
		:empty-content-message="t('approval', 'No pending approvals')"
		empty-content-icon="icon-checkmark">
		<template #default="{ item }">
			<NcDashboardWidgetItem
				:target-url="item.url"
				:main-text="item.fileName"
				:sub-text="item.formattedDate">
				<template #avatar>
					<img :src="item.iconUrl" :alt="item.mimetype" class="avatar-icon">
				</template>
			</NcDashboardWidgetItem>
		</template>
		<!-- TODO: Add actions, like a 'View all' button -->
	</NcDashboardWidget>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { NcDashboardWidget, NcDashboardWidgetItem, useFormatDateTime } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'DashboardPending',
	components: {
		NcDashboardWidget,
		NcDashboardWidgetItem,
	},
	props: {
		title: {
			type: String,
			default: t('approval', 'Pending approvals'),
		},
	},
	setup() {
		const formatter = useFormatDateTime()
		const relativeDateFormatter = formatter.formatRelativeDateTime || formatter.formatDate || formatter.formatDateTime
		return { relativeDateFormatter }
	},
	data() {
		return {
			loading: true,
			items: [],
		}
	},
	mounted() {
		this.fetchPendingApprovals()
	},
	methods: {
		t(
			app,
			text,
		) {
			return t(app, text)
		},
		async fetchPendingApprovals() {
			this.loading = true
			const url = generateOcsUrl('apps/approval/api/v1/pendings')
			try {
				const response = await axios.get(url)
				// eslint-disable-next-line no-console
				console.log('API Response:', response.data)
				this.items = response.data.ocs.data.map((pendingItem) => {
					const apiTimestampInSeconds = pendingItem.activity && pendingItem.activity.timestamp
					const timestamp = apiTimestampInSeconds
						? apiTimestampInSeconds * 1000
						: Date.now()

					return {
						id: pendingItem.file_id,
						fileName: pendingItem.file_name,
						mimetype: pendingItem.mimetype,
						url: '#', // TODO: Construct actual URL
						formattedDate: this.relativeDateFormatter(timestamp),
						iconUrl: OC.MimeType.getIconUrl(pendingItem.mimetype),
					}
				})
				// eslint-disable-next-line no-console
				console.log('Mapped items:', this.items)
			} catch (error) {
				console.error('Error fetching pending approvals:', error)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.avatar-icon {
	width: var(--default-clickable-area, 44px); // Use Nextcloud CSS variable if available
	height: var(--default-clickable-area, 44px);
	border-radius: var(--border-radius-rounded, 50%); // Use Nextcloud CSS variable
	object-fit: cover;
}

/* Styles can be kept or removed, likely not affecting this test */
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
