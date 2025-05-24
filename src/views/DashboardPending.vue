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
				:key="item.id"
				:title="item.title"
				:subtitle="item.subtitle"
				:link="item.link"
				:icon="item.iconUrl ? item.iconUrl : item.iconClass"
				:datetime="item.date ? new Date(item.date) : null" />
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
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'

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
		widgetId: {
			type: String,
			required: true,
		},
		itemApiVersion: {
			type: [String, Number],
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
		await this.fetchItems()
	},
	methods: {
		async fetchItems() {
			this.loading = true
			try {
				const url = generateUrl(
					`/ocs/v2.php/apps/dashboard/api/v1/widget-items/${this.widgetId}?format=json&item_api_version=${this.itemApiVersion}`,
				)
				const response = await axios.get(url)
				if (response.data && response.data.ocs && response.data.ocs.data) {
					this.items = response.data.ocs.data
				} else {
					this.items = []
					showError(this.t('approval', 'Could not fetch pending approvals: Invalid API response'))
				}
			} catch (e) {
				console.error(e)
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
