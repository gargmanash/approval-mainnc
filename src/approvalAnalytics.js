import Vue from 'vue'
import { translate as t } from '@nextcloud/l10n'
import ApprovalAnalytics from './views/ApprovalAnalytics.vue'

Vue.prototype.t = t

const View = Vue.extend(ApprovalAnalytics)
new View().$mount('#approval-analytics-content')
