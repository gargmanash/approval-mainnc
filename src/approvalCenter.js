// eslint-disable-next-line no-console
console.log('APPROVALCENTER.JS SCRIPT LOADED AND PARSING')

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// import Vue from 'vue'
// import ApprovalCenterView from './views/ApprovalCenterView.vue'

// Import other necessary Nextcloud Vue components or libraries if needed
// e.g., import store from '@nextcloud/vuex'
// import { NcButton } from '@nextcloud/vue'

const initApprovalCenter = () => {
	const el = document.getElementById('approval-center-vue-root')
	if (el) {
		el.textContent = 'JavaScript reached initApprovalCenter and found the root element.'
		/* const app = new Vue({
			render: h => h(ApprovalCenterView),
		})

		// If using Vuex store:
		// app.use(store)

		// Register Nextcloud components globally if needed, e.g.:
		// app.component('NcButton', NcButton)

		app.$mount(el) */
	} else {
		console.error('Approval Center: Could not find root element #approval-center-vue-root')
	}
}

// Initialize when the DOM is ready
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', initApprovalCenter)
} else {
	initApprovalCenter()
}
