<template>
	<div id="approval-analytics-page">
		<h1>{{ t('approval', 'Approval Analytics') }}</h1>

		<!-- Workflow KPIs Summary Table -->
		<div v-if="workflowKpis && workflowKpis.length" class="workflow-kpi-summary">
			<h2>{{ t('approval', 'Workflow Summary') }}</h2>
			<div class="table-scroll-wrapper">
				<table class="analytics-table summary-table">
					<thead>
						<tr>
							<th>{{ t('approval', 'Workflow Description') }}</th>
							<th>{{ t('approval', 'Pending') }}</th>
							<th>{{ t('approval', 'Approved') }}</th>
							<th>{{ t('approval', 'Rejected') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="kpi in workflowKpis" :key="kpi.rule_id">
							<td>{{ kpi.description }}</td>
							<td>{{ kpi.pending_count }}</td>
							<td>{{ kpi.approved_count }}</td>
							<td>{{ kpi.rejected_count }}</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>

		<div v-if="loading">
			<p>
				{{ t('approval', 'Loading data...') }}
			</p>
		</div>
		<div v-else>
			<div v-if="Object.keys(filesGroupedByWorkflow).length">
				<div v-for="(workflowData, workflowName) in filesGroupedByWorkflow" :key="workflowName" class="workflow-group">
					<h2>{{ workflowName }}</h2>
					<div class="table-scroll-wrapper">
						<table v-if="workflowData.paginatedFiles.length" class="analytics-table">
							<thead>
								<tr>
									<th>{{ t('approval', 'File Name') }}</th>
									<th>{{ t('approval', 'File Path') }}</th>
									<th>{{ t('approval', 'Status') }}</th>
									<th>{{ t('approval', 'Sent At') }}</th>
									<th>{{ t('approval', 'Approved At') }}</th>
									<th>{{ t('approval', 'Rejected At') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="file in workflowData.paginatedFiles" :key="file.file_id + '-' + file.rule_id">
									<td>{{ getFileName(file.path) }}</td>
									<td>{{ getDisplayPath(file.path) }}</td>
									<td>{{ getStatusLabel(file.status_code) }}</td>
									<td>{{ formatTimestamp(file.sent_at) }}</td>
									<td>{{ formatTimestamp(file.status_code === 2 ? file.approved_at : null) }}</td>
									<td>{{ formatTimestamp(file.status_code === 3 ? file.rejected_at : null) }}</td>
								</tr>
							</tbody>
						</table>
					</div>
					<div v-if="workflowData.totalPages > 1" class="pagination-controls">
						<button :disabled="workflowData.currentPage === 1" @click="prevPage(workflowName)">
							{{ t('approval', 'Previous') }}
						</button>
						<span>
							{{ t('approval', 'Page {currentPage} of {totalPages}', { currentPage: workflowData.currentPage, totalPages: workflowData.totalPages })
							}}
						</span>
						<button :disabled="workflowData.currentPage === workflowData.totalPages" @click="nextPage(workflowName)">
							{{ t('approval', 'Next') }}
						</button>
					</div>
					<p v-if="!(workflowData.allFiles && workflowData.allFiles.length)">
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

const ITEMS_PER_PAGE = 10 // Define how many items per page

export default {
	name: 'ApprovalAnalytics',
	props: {
		workflowKpis: {
			type: Array,
			default: () => [],
		},
	},
	data() {
		return {
			t,
			allApprovalFiles: [],
			rules: {},
			loading: false,
			// Store pagination state per workflow
			// e.g., { 'Workflow A': { currentPage: 1, itemsPerPage: ITEMS_PER_PAGE }, ... }
			paginationState: {},
		}
	},
	computed: {
		filesGroupedByWorkflow() {
			const grouped = {}
			this.allApprovalFiles.forEach(file => {
				const ruleDescription = this.getRuleDescription(file.rule_id)
				if (!grouped[ruleDescription]) {
					// Initialize structure for each workflow group
					grouped[ruleDescription] = {
						allFiles: [],
						currentPage: this.paginationState[ruleDescription]?.currentPage || 1,
						itemsPerPage: this.paginationState[ruleDescription]?.itemsPerPage || ITEMS_PER_PAGE,
						// paginatedFiles will be computed from allFiles, currentPage, itemsPerPage
						// totalPages will be computed from allFiles and itemsPerPage
					}
				}
				grouped[ruleDescription].allFiles.push(file)
			})

			// Now, for each group, compute paginatedFiles and totalPages
			for (const wfName in grouped) {
				const group = grouped[wfName]
				const startIndex = (group.currentPage - 1) * group.itemsPerPage
				const endIndex = startIndex + group.itemsPerPage
				group.paginatedFiles = group.allFiles.slice(startIndex, endIndex)
				group.totalPages = Math.ceil(group.allFiles.length / group.itemsPerPage)

				// Ensure pagination state is initialized for new workflows
				if (!this.paginationState[wfName]) {
					this.$set(this.paginationState, wfName, {
						currentPage: 1,
						itemsPerPage: ITEMS_PER_PAGE,
					})
				}
			}
			return grouped
		},
	},
	async mounted() {
		this.loading = true
		try {
			await this.fetchRules()
			await this.fetchAllApprovalFiles()
			// Initialize pagination state after data is fetched
			this.initializePaginationState()
		} finally {
			this.loading = false
		}
	},
	methods: {
		initializePaginationState() {
			const newPaginationState = {}
			for (const file of this.allApprovalFiles) {
				const ruleDescription = this.getRuleDescription(file.rule_id)
				if (!newPaginationState[ruleDescription]) {
					newPaginationState[ruleDescription] = {
						currentPage: 1,
						itemsPerPage: ITEMS_PER_PAGE,
					}
				}
			}
			this.paginationState = newPaginationState
		},
		prevPage(workflowName) {
			if (this.paginationState[workflowName] && this.paginationState[workflowName].currentPage > 1) {
				this.paginationState[workflowName].currentPage--
			}
		},
		nextPage(workflowName) {
			const group = this.filesGroupedByWorkflow[workflowName]
			if (group && this.paginationState[workflowName].currentPage < group.totalPages) {
				this.paginationState[workflowName].currentPage++
			}
		},
		async fetchAllApprovalFiles() {
			try {
				const response = await axios.get(generateUrl('/apps/approval/all-approval-files'))
				this.allApprovalFiles = response.data || []
				this.initializePaginationState() // Re-initialize pagination if files change
			} catch (e) {
				console.error('Error fetching approval files:', e)
			}
		},
		async fetchRules() {
			try {
				const response = await axios.get(generateUrl('/apps/approval/rules'))
				const rulesArray = response.data ? (Array.isArray(response.data) ? response.data : Object.values(response.data)) : []
				this.rules = rulesArray.reduce((acc, rule) => {
					if (rule && rule.id) {
						acc[rule.id] = rule
					}
					return acc
				}, {})
			} catch (e) {
				console.error('Error fetching rules:', e)
				this.rules = {}
			}
		},
		getFileName(path) {
			if (!path) return ''
			const parts = path.split('/')
			return parts[parts.length - 1]
		},
		getDisplayPath(path) {
			if (!path) return ''
			// Remove the leading /username/files/ part
			// e.g., /manas/files/Shared/doc.txt -> Shared/doc.txt
			return path.replace(/^\/[^/]+\/files\//, '')
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

.table-scroll-wrapper {
	overflow-x: auto;
	margin-bottom: 10px; /* Adjusted space for pagination controls */
}

.analytics-table {
	width: 100%;
	border-collapse: collapse;
	margin-top: 10px;
	/* margin-bottom is removed as pagination controls will have their own margin */

	th, td {
		border: 1px solid var(--color-border);
		padding: 6px 8px;
		text-align: start;
	}

	th {
		background-color: var(--color-background-hover);
	}
}

.summary-table th,
.summary-table td {
	text-align: center;
}

.summary-table th:first-child,
.summary-table td:first-child {
	text-align: start;
}

.workflow-group h2 {
	margin-top: 20px;
	margin-bottom: 10px;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 5px;
}

.pagination-controls {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-top: 10px;
	margin-bottom: 20px;

	button {
		padding: 5px 10px;
		border: 1px solid var(--color-border);
		background-color: var(--color-main-background);
		cursor: pointer;

		&:disabled {
			cursor: not-allowed;
			opacity: 0.5;
		}
	}

	span {
		margin: 0 10px;
	}
}

h1, h2 {
	color: var(--color-main-text);
}

p {
	color: var(--color-text-maxcontrast);
}
</style>
