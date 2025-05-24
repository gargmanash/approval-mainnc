<!--
  - SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<NcDashboardWidget
			:title="widgetTitle"
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
		</NcDashboardWidget>
		<div v-if="items.length > 0 && !loading" class="dashboard-actions-footer">
			<NcButton :href="generateUrl('/apps/approval')">
				{{ t('approval', 'View all') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import { NcDashboardWidget, NcDashboardWidgetItem, NcButton } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import moment from '@nextcloud/moment'

export default {
	name: 'DashboardPending',
	components: {
		NcDashboardWidget,
		NcDashboardWidgetItem,
		NcButton,
	},
	props: {
		title: {
			type: String,
			default: t('approval', 'Pending approvals'),
		},
	},
	data() {
		return {
			loading: true,
			items: [],
		}
	},
	computed: {
		widgetTitle() {
			let currentTitle = this.title
			if (this.items.length > 0) {
				currentTitle = `${this.title} (${this.items.length})`
			}
			// eslint-disable-next-line no-console
			console.log('Computed widgetTitle:', currentTitle, 'Items length:', this.items.length)
			return currentTitle
		},
		generateUrl() {
			return generateUrl
		}
	},
	created() {
		this.fetchPendingApprovals()
	},
	updated() {
		// eslint-disable-next-line no-console
		console.log('Component updated. Current widgetTitle from this:', this.widgetTitle)
	},
	methods: {
		t(
			app,
			text,
		) {
			return t(app, text)
		},
		getFormattedDate(timestampInSeconds) {
			if (!timestampInSeconds) return ''
			return moment.unix(timestampInSeconds).format('L LT')
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
					const itemUrl = generateUrl('/f/' + pendingItem.file_id)
					return {
						id: pendingItem.file_id,
						fileName: pendingItem.file_name,
						mimetype: pendingItem.mimetype,
						url: itemUrl,
						formattedDate: this.getFormattedDate(apiTimestampInSeconds),
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

.dashboard-actions-footer {
	display: flex;
	justify-content: center;
	padding-top: 16px; // Add some spacing
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
