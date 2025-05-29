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

		<div class="analytics-content-area">
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
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
// axios and generateUrl no longer needed at the top level here

const ITEMS_PER_PAGE = 10

export default {
	name: 'ApprovalAnalytics',
	props: {
		workflowKpis: {
			type: Array,
			default: () => [],
		},
		allApprovalFilesData: {
			type: Array,
			default: () => [],
		},
		workflowRules: { // New prop for rules
			type: Array,
			default: () => [],
		},
	},
	data() {
		return {
			t,
			// rules: {}, // Removed, will use computed rulesMap from workflowRules prop
			loading: false, // Kept for any independent loading state if needed, or can be removed if parent handles all loading indications
			paginationState: {},
		}
	},
	computed: {
		rulesMap() {
			// eslint-disable-next-line no-console
			// console.log('[ApprovalAnalytics] Recomputing rulesMap, workflowRules length:', this.workflowRules.length);
			return this.workflowRules.reduce((acc, rule) => {
				if (rule && rule.id) {
					acc[rule.id] = rule
				}
				return acc
			}, {})
		},
		filesGroupedByWorkflow() {
			const grouped = {}
			this.allApprovalFilesData.forEach(file => {
				// Use this.rulesMap here
				const ruleDescription = this.getRuleDescription(file.rule_id)
				if (!grouped[ruleDescription]) {
					grouped[ruleDescription] = {
						allFiles: [],
						currentPage: this.paginationState[ruleDescription]?.currentPage || 1,
						itemsPerPage: this.paginationState[ruleDescription]?.itemsPerPage || ITEMS_PER_PAGE,
					}
				}
				grouped[ruleDescription].allFiles.push(file)
			})

			for (const wfName in grouped) {
				const group = grouped[wfName]
				const startIndex = (group.currentPage - 1) * group.itemsPerPage
				const endIndex = startIndex + group.itemsPerPage
				group.paginatedFiles = group.allFiles.slice(startIndex, endIndex)
				group.totalPages = Math.ceil(group.allFiles.length / group.itemsPerPage)
			}
			return grouped
		},
	},
	watch: {
		allApprovalFilesData: {
			handler(newData) {
				// console.log('[ApprovalAnalytics] allApprovalFilesData prop changed, new length:', newData ? newData.length : 0);
				if (newData && Object.keys(this.rulesMap).length > 0) { // Ensure rulesMap is also ready
					this.initializePaginationState()
				} else if (newData && Object.keys(this.rulesMap).length === 0) {
					// console.log('[ApprovalAnalytics] allApprovalFilesData changed, but rulesMap is not ready yet for pagination init.');
				}
			},
			// immediate: true, // defer to rulesMap watcher or combined condition
		},
		workflowRules: {
			handler(newRules) {
				// console.log('[ApprovalAnalytics] workflowRules prop changed, new length:', newRules ? newRules.length : 0);
				if (newRules && this.allApprovalFilesData && this.allApprovalFilesData.length > 0) {
					this.initializePaginationState()
				} else if (newRules) {
					// console.log('[ApprovalAnalytics] workflowRules changed, but allApprovalFilesData is not ready yet for pagination init.');
				}
			},
			// immediate: true // defer to combined condition
		},
	},
	async mounted() {
		// console.log('[ApprovalAnalytics] Mounted. Waiting for props to trigger pagination init via watchers.');
		// Initial check in case props are already populated when mounted
		if (this.allApprovalFilesData && this.allApprovalFilesData.length > 0 && Object.keys(this.rulesMap).length > 0) {
			// console.log('[ApprovalAnalytics] Props available on mount, initializing pagination.');
			this.initializePaginationState()
		}
	},
	methods: {
		initializePaginationState() {
			// console.log('[ApprovalAnalytics] initializePaginationState called. Files length:', this.allApprovalFilesData?.length, 'RulesMap keys:', Object.keys(this.rulesMap).length);
			if (!this.allApprovalFilesData || Object.keys(this.rulesMap).length === 0) {
				// console.warn('[ApprovalAnalytics] initializePaginationState: Missing data for initialization.');
				return
			}
			const newPaginationState = { ...this.paginationState }
			const uniqueWorkflowDescriptions = new Set(
				this.allApprovalFilesData.map(file => this.getRuleDescription(file.rule_id)),
			)

			uniqueWorkflowDescriptions.forEach(ruleDescription => {
				if (!newPaginationState[ruleDescription]) {
					newPaginationState[ruleDescription] = {
						currentPage: 1,
						itemsPerPage: ITEMS_PER_PAGE,
					}
				}
			})
			this.paginationState = newPaginationState
			// console.log('[ApprovalAnalytics] paginationState updated:', JSON.parse(JSON.stringify(this.paginationState)));
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
		// fetchRules() method removed
		getFileName(path) {
			if (!path) return ''
			const parts = path.split('/')
			return parts[parts.length - 1]
		},
		getDisplayPath(path) {
			if (!path) return ''
			return path.replace(/^\/[^/]+\/files\//, '')
		},
		getRuleDescription(ruleId) {
			// Use this.rulesMap here
			const rule = this.rulesMap[ruleId]
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
	padding: 20px; // ADDED overall padding here
	display: flex;
	flex-direction: column;
	height: 100%; // Fill .right-pane
	overflow: hidden; // Ensure this page itself doesn't scroll
}

.workflow-kpi-summary {
	flex-shrink: 0; // Don't let summary shrink
	margin-bottom: 20px; // Space after summary
	/* padding: 0 20px; */ // REMOVED - parent handles padding
}

// New wrapper for content below summary
.analytics-content-area {
	flex-grow: 1; // Takes remaining vertical space
	display: flex;
	flex-direction: column;
	overflow-y: auto; // If content (multiple workflows) overflows vertically
	/* padding: 0 20px; */ // REMOVED - parent handles padding
}

.table-scroll-wrapper {
	overflow-x: auto;
	margin-bottom: 10px;
	display: block;
	width: 100%;
	/* flex-grow: 1; // Might not be needed now, let analytics-content-area handle vertical growth */
}

.analytics-table {
	min-width: max-content; // Make table as wide as its content
	border-collapse: collapse;
	margin-top: 10px;

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
