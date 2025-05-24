<!--
  - SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDashboardWidget title="Super Simple Test" :items="items" :loading="loading">
		<template #default="{ item }">
			<NcDashboardWidgetItem
				:main-text="item.name"
				sub-text="This is a static sub-text" />
		</template>
		<!-- Actions slot can be re-added later if default content works -->
		<!-- Empty slot removed for this minimal test -->
	</NcDashboardWidget>
</template>

<script>
/* eslint-disable no-console */
// Importing NcDashboardWidgetItem as well
import { NcDashboardWidget, NcDashboardWidgetItem } from '@nextcloud/vue'

export default {
	name: 'DashboardPending',
	components: {
		NcDashboardWidget,
		NcDashboardWidgetItem,
	},
	props: {
		// title prop is passed by dashboardPending.js, let's keep it defined
		// though NcDashboardWidget above uses a hardcoded title for this test.
		title: {
			type: String,
			default: 'Pending Approvals (fallback title)',
		},
	},
	data() {
		return {
			loading: false,
			items: [
				{ id: 1, name: 'Static Test Item 1' },
				{ id: 2, name: 'Static Test Item 2' },
			],
		}
	},
	// No methods, no lifecycle hooks for this minimal test
}
</script>

<style scoped lang="scss">
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
