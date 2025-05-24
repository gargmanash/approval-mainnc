<!--
  - SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDashboardWidget title="Super Simple Test">
		<template #actions>
			<NcButton type="tertiary">
				Test Action
			</NcButton>
		</template>
		<template #default>
			<p>This is static default slot content.</p>
		</template>
		<template #empty>
			<p>This is static empty slot content.</p>
		</template>
	</NcDashboardWidget>
</template>

<script>
/* eslint-disable no-console */
// Importing only what's absolutely necessary for this static test
import { NcDashboardWidget, NcButton } from '@nextcloud/vue'

export default {
	name: 'DashboardPending',
	components: {
		NcDashboardWidget,
		NcButton,
	},
	props: {
		// title prop is passed by dashboardPending.js, let's keep it defined
		// though NcDashboardWidget above uses a hardcoded title for this test.
		title: {
			type: String,
			default: 'Pending Approvals (fallback title)',
		},
	},
	// No data, no methods, no lifecycle hooks for this minimal test
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
