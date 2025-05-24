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
		new Vue({
			render: h => h(DashboardPending, {
				props: {
					title: widget.title,
					widgetId: widget.id,
					itemApiVersion: widget.item_api_version,
				},
			}),
		}).$mount(el)
	})
})
