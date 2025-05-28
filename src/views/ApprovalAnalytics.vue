<template>
	<div id="approval-analytics-page">
		<h1>{{ t('approval', 'Approval Analytics') }}</h1>
		<div v-if="loading">
			<p>
				{{ t('approval', 'Loading data...') }}
			</p>
		</div>
		<div v-else>
			<div v-if="Object.keys(filesGroupedByWorkflow).length">
				<div v-for="(files, workflowName) in filesGroupedByWorkflow" :key="workflowName" class="workflow-group">
					<h2>{{ workflowName }}</h2>
					<table v-if="files.length" class="analytics-table">
						<thead>
							<tr>
								<th>{{ t('approval', 'File Name') }}</th>
								<th>{{ t('approval', 'Status') }}</th>
								<th>{{ t('approval', 'Sent At') }}</th>
								<th>{{ t('approval', 'Approved At') }}</th>
								<th>{{ t('approval', 'Rejected At') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="file in files" :key="file.id + '-' + file.rule_id">
								<td>{{ getFileName(file.path) }}</td>
								<td>{{ getStatusLabel(file.status_code) }}</td>
								<td>{{ formatTimestamp(file.sent_at) }}</td>
								<td>{{ formatTimestamp(file.approved_at) }}</td>
								<td>{{ formatTimestamp(file.rejected_at) }}</td>
							</tr>
						</tbody>
					</table>
					<p v-else>
						{{ t('approval', 'No approval files found for this workflow.') }}
					</p>
				</div>
			</div>
			<p v-else>
				{{ t('approval', 'No approval files found.') }}
			</p>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ApprovalAnalytics',
	data() {
		return {
			t,
			allApprovalFiles: [],
			rules: {},
			loading: false,
		}
	},
	computed: {
		filesGroupedByWorkflow() {
			const grouped = {}
			this.allApprovalFiles.forEach(file => {
				const ruleDescription = this.getRuleDescription(file.rule_id)
				if (!grouped[ruleDescription]) {
					grouped[ruleDescription] = []
				}
				grouped[ruleDescription].push(file)
			})
			return grouped
		},
	},
	async mounted() {
		this.loading = true
		try {
			await this.fetchRules()
			await this.fetchAllApprovalFiles()
		} finally {
			this.loading = false
		}
	},
	methods: {
		async fetchAllApprovalFiles() {
			try {
				const response = await axios.get(generateUrl('/apps/approval/all-approval-files'))
				this.allApprovalFiles = response.data || []
			} catch (e) {
				console.error('Error fetching approval files:', e)
			}
		},
		async fetchRules() {
			try {
				const response = await axios.get(generateUrl('/apps/approval/rules'))
				this.rules = (response.data || []).reduce((acc, rule) => {
					acc[rule.id] = rule
					return acc
				}, {})
			} catch (e) {
				console.error('Error fetching rules:', e)
			}
		},
		getFileName(path) {
			if (!path) return ''
			const parts = path.split('/')
			return parts[parts.length - 1]
		},
		getRuleDescription(ruleId) {
			const rule = this.rules[ruleId]
			return rule ? rule.description : t('approval', 'Workflow') + ' ' + ruleId
		},
		getStatusLabel(status) {
			switch (status) {
			case 1: return t('approval', 'Pending')
			case 2: return t('approval', 'Approved')
			case 3: return t('approval', 'Rejected')
			default: return status
			}
		},
		formatTimestamp(ts) {
			if (!ts) return '—'
			const date = new Date(ts * 1000)
			return date.toLocaleString()
		},
	},
}
</script>

<style scoped lang="scss">
#approval-analytics-page {
	padding: 20px;
}

.analytics-table {
	width: 100%;
	border-collapse: collapse;
	margin-top: 10px;
	margin-bottom: 30px;

	th, td {
		border: 1px solid var(--color-border);
		padding: 6px 8px;
		text-align: start;
	}

	th {
		background-color: var(--color-background-hover);
	}
}

.workflow-group h2 {
	margin-top: 20px;
	margin-bottom: 10px;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 5px;
}

h1, h2 {
	color: var(--color-main-text);
}

p {
	color: var(--color-text-maxcontrast);
}
</style>
