<template>
	<div id="approval-analytics-page">
		<!-- <h1>{{ t('approval', 'Approval Analytics') }}</h1> -->

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
										<th @click="sortByColumn(workflowName, 'fileName')">
											{{ t('approval', 'File Name') }}
											<span v-if="sortingState[workflowName] && sortingState[workflowName].key === 'fileName'"
												:class="['sort-arrow', sortingState[workflowName].order]">
											</span>
										</th>
										<th @click="sortByColumn(workflowName, 'filePath')">
											{{ t('approval', 'File Path') }}
											<span v-if="sortingState[workflowName] && sortingState[workflowName].key === 'filePath'"
												:class="['sort-arrow', sortingState[workflowName].order]">
											</span>
										</th>
										<th @click="sortByColumn(workflowName, 'status')">
											{{ t('approval', 'Status') }}
											<span v-if="sortingState[workflowName] && sortingState[workflowName].key === 'status'"
												:class="['sort-arrow', sortingState[workflowName].order]">
											</span>
										</th>
										<th @click="sortByColumn(workflowName, 'sent_at')">
											{{ t('approval', 'Sent At') }}
											<span v-if="sortingState[workflowName] && sortingState[workflowName].key === 'sent_at'"
												:class="['sort-arrow', sortingState[workflowName].order]">
											</span>
										</th>
										<th @click="sortByColumn(workflowName, 'approved_at')">
											{{ t('approval', 'Approved At') }}
											<span v-if="sortingState[workflowName] && sortingState[workflowName].key === 'approved_at'"
												:class="['sort-arrow', sortingState[workflowName].order]">
											</span>
										</th>
										<th @click="sortByColumn(workflowName, 'rejected_at')">
											{{ t('approval', 'Rejected At') }}
											<span v-if="sortingState[workflowName] && sortingState[workflowName].key === 'rejected_at'"
												:class="['sort-arrow', sortingState[workflowName].order]">
											</span>
										</th>
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
						<div v-if="workflowData.totalPages > 1 || workflowData.allFiles.length > ITEMS_PER_PAGE" class="pagination-controls">
							<div class="items-per-page-selector">
								<label :for="`items-per-page-${workflowName}`">{{ t('approval', 'Rows per page:') }}</label>
								<select :id="`items-per-page-${workflowName}`"
									:value="paginationState[workflowName]?.itemsPerPage || ITEMS_PER_PAGE"
									@change="handleItemsPerPageChange(workflowName, $event.target.value)">
									<option v-for="option in ROWS_PER_PAGE_OPTIONS" :key="option" :value="option">
										{{ option }}
									</option>
								</select>
							</div>
							<div class="page-navigation">
								<button :disabled="workflowData.currentPage === 1" @click="prevPage(workflowName)">
									{{ t('approval', 'Previous') }}
								</button>
								<div class="go-to-page-input">
									<label :for="`go-to-page-${workflowName}`">{{ t('approval', 'Page') }}</label>
									<input :id="`go-to-page-${workflowName}`"
										type="number"
										:value="workflowData.currentPage"
										min="1"
										:max="workflowData.totalPages"
										@change="handleGoToPage(workflowName, $event.target.value, workflowData.totalPages)"
										@keyup.enter="handleGoToPage(workflowName, $event.target.value, workflowData.totalPages)">
									<span>{{ t('approval', 'of {totalPages}', { totalPages: workflowData.totalPages }) }}</span>
								</div>
								<button :disabled="workflowData.currentPage === workflowData.totalPages" @click="nextPage(workflowName)">
									{{ t('approval', 'Next') }}
								</button>
							</div>
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
const ROWS_PER_PAGE_OPTIONS = [10, 25, 50, 100]

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
			sortingState: {}, // Added for sorting
			ROWS_PER_PAGE_OPTIONS,
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
				const ruleDescription = this.getRuleDescription(file.rule_id)
				if (!grouped[ruleDescription]) {
					grouped[ruleDescription] = {
						allFiles: [],
						currentPage: this.paginationState[ruleDescription]?.currentPage || 1,
						itemsPerPage: this.paginationState[ruleDescription]?.itemsPerPage || ITEMS_PER_PAGE,
						sortKey: this.sortingState[ruleDescription]?.key || 'sent_at', // Ensure sortKey is available
						sortOrder: this.sortingState[ruleDescription]?.order || 'desc', // Ensure sortOrder is available
					}
				}
				grouped[ruleDescription].allFiles.push(file)
			})

			for (const wfName in grouped) {
				const group = grouped[wfName]
				const sortKey = this.sortingState[wfName]?.key || 'sent_at' // Fallback if not yet initialized
				const sortOrder = this.sortingState[wfName]?.order || 'desc' // Fallback

				group.allFiles.sort((a, b) => {
					let valA, valB

					if (sortKey === 'fileName') {
						valA = this.getFileName(a.path).toLowerCase()
						valB = this.getFileName(b.path).toLowerCase()
					} else if (sortKey === 'filePath') {
						valA = this.getDisplayPath(a.path).toLowerCase()
						valB = this.getDisplayPath(b.path).toLowerCase()
					} else if (sortKey === 'status') {
						valA = this.getStatusLabel(a.status_code).toLowerCase()
						valB = this.getStatusLabel(b.status_code).toLowerCase()
					} else if (sortKey === 'sent_at' || sortKey === 'approved_at' || sortKey === 'rejected_at') {
						valA = a[sortKey] || 0 // Timestamps, handle nulls for sorting
						valB = b[sortKey] || 0
					} else {
						valA = a[sortKey]
						valB = b[sortKey]
					}

					if (valA < valB) return sortOrder === 'asc' ? -1 : 1;
					if (valA > valB) return sortOrder === 'asc' ? 1 : -1;
					return 0;
				})

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
			const newSortingState = { ...this.sortingState } // Initialize sorting state here too

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
				if (!newSortingState[ruleDescription]) { // Default sort state for new workflows
					newSortingState[ruleDescription] = {
						key: 'sent_at', // Default sort key
						order: 'desc',    // Default sort order
					}
				}
			})
			this.paginationState = newPaginationState
			this.sortingState = newSortingState // Assign new sorting state
			// Add ROWS_PER_PAGE_OPTIONS to data to make it accessible in the template
			this.ROWS_PER_PAGE_OPTIONS = ROWS_PER_PAGE_OPTIONS
			// console.log('[ApprovalAnalytics] paginationState updated:', JSON.parse(JSON.stringify(this.paginationState)));
			// console.log('[ApprovalAnalytics] sortingState updated:', JSON.parse(JSON.stringify(this.sortingState)));
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
		handleItemsPerPageChange(workflowName, newSize) {
			const newItemsPerPage = parseInt(newSize, 10);
			if (this.paginationState[workflowName]) {
				this.paginationState[workflowName].itemsPerPage = newItemsPerPage;
				this.paginationState[workflowName].currentPage = 1; // Reset to page 1
				this.$set(this.paginationState, workflowName, { ...this.paginationState[workflowName] });
			}
		},
		handleGoToPage(workflowName, pageNumber, totalPages) {
			let newPage = parseInt(pageNumber, 10);
			if (isNaN(newPage)) return;

			if (newPage < 1) newPage = 1;
			if (newPage > totalPages) newPage = totalPages;

			if (this.paginationState[workflowName]) {
				this.paginationState[workflowName].currentPage = newPage;
				this.$set(this.paginationState, workflowName, { ...this.paginationState[workflowName] });
			}
		},
		sortByColumn(workflowName, key) {
			// console.log(`[ApprovalAnalytics] sortByColumn called for ${workflowName}, key: ${key}`);
			const currentSort = this.sortingState[workflowName];
			if (currentSort.key === key) {
				currentSort.order = currentSort.order === 'asc' ? 'desc' : 'asc';
			} else {
				currentSort.key = key;
				currentSort.order = 'asc'; // Default to ascending for a new column
			}
			this.$set(this.sortingState, workflowName, currentSort);
			// Reset to page 1 when sort order changes, to avoid confusion
			if (this.paginationState[workflowName]) {
				this.paginationState[workflowName].currentPage = 1;
			}
			// console.log(`[ApprovalAnalytics] New sortingState for ${workflowName}:`, JSON.parse(JSON.stringify(this.sortingState[workflowName])));
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
	padding: 40px; /* Further increased padding */
	display: flex;
	flex-direction: column;
	height: 100%; // Fill the area provided by NcAppContent
}

/* Target the H1 specifically if it's the first child causing an issue */
/* Removing this block to allow default/parent padding to take effect
#approval-analytics-page > h1 {
	margin-top: 0;
	padding-inline-start: 0;
	margin-inline-start: 0;
}
*/

.workflow-kpi-summary {
	flex-shrink: 0;
	margin-bottom: 20px;
}

.analytics-content-area {
	flex-grow: 1;
	display: flex;
	flex-direction: column;
	overflow-y: auto; // For vertical scroll of multiple tables
	// padding: 0 20px; // This was removed before, keep it removed.
}

.table-scroll-wrapper {
	overflow-x: auto;
	margin-bottom: 10px;
	display: block; // Keep as block to allow width: 100%
	width: 100%;
}

.analytics-table {
	border-collapse: collapse;
	margin-top: 10px;
	table-layout: auto; /* Allow table to size based on content */
	width: max-content; /* Ensure table can be wider than its container */
	min-width: 100%; /* But at least take full width of scroll wrapper */

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
	margin-top: 15px;
	padding: 10px;
	border-top: 1px solid var(--color-border);
}

.items-per-page-selector,
.page-navigation,
.go-to-page-input { /* Added go-to-page-input for future use */
	display: flex;
	align-items: center;
	gap: 8px; /* Spacing between elements */
}

.items-per-page-selector label,
.go-to-page-input label { /* Added for future use */
	margin-right: 5px;
}

.items-per-page-selector select,
.go-to-page-input input { /* Added for future use */
	padding: 5px;
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
	width: 70px; /* Give a specific width to the input */
	text-align: center;
}

.pagination-controls button {
	padding: 8px 12px;
	background-color: var(--color-primary-element);
	color: var(--color-primary-element-text);
	border: none;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.pagination-controls button:disabled {
	background-color: var(--color-background-dark);
	color: var(--color-text-disabled);
	cursor: not-allowed;
}

h1, h2 {
	color: var(--color-main-text);
}

p {
	color: var(--color-text-maxcontrast);
}

.analytics-table th {
	background-color: var(--color-background-dark);
	color: var(--color-main-text);
	padding: 12px 15px;
	text-align: start;
	border-bottom: 2px solid var(--color-border-maxcontrast);
	cursor: pointer; /* Add cursor pointer to indicate clickable headers */
}

.sort-arrow {
	display: inline-block;
	width: 0;
	height: 0;
	margin-left: 5px;
	vertical-align: middle;
	border-left: 5px solid transparent;
	border-right: 5px solid transparent;
}

.sort-arrow.asc {
	border-bottom: 5px solid var(--color-main-text);
}

.sort-arrow.desc {
	border-top: 5px solid var(--color-main-text);
}
</style>
