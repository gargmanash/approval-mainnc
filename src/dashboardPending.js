/* jshint esversion: 6 */

/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import Vue from 'vue'
import './bootstrap.js'
import DashboardPending from './views/DashboardPending.vue'

document.addEventListener('DOMContentLoaded', function() {
	OCA.Dashboard.register('approval_pending', (el, { widget }) => {
		// Removed console.log for brevity now, can be re-added if needed for debugging
		new Vue({
			render: h => h(DashboardPending, {
				props: {
					title: widget.title, // Pass only title, or nothing if component defines its own
					// widgetId and itemApiVersion removed
				},
			}),
		}).$mount(el)
	})
})
