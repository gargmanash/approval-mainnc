<template>
	<div id="approval-center-view">
		<NcAppContent app-name="approval">
			<template #app-navigation>
				<NcAppNavigation :title="t('approval', 'Approval Center')">
					<NcAppNavigationItem :title="t('approval', 'Approval File Tree')" @click="currentSection = 'tree'" :active="currentSection === 'tree'" />
					<NcAppNavigationItem :title="t('approval', 'Workflow KPIs')" @click="currentSection = 'kpis'" :active="currentSection === 'kpis'" />
				</NcAppNavigation>
			</template>

			<div class="app-content-container">
				<h1>{{ t('approval', 'Approval Center & KPIs') }}</h1>

				<div v-if="loading">
					<NcLoadingIcon />
					<p>{{ t('approval', 'Loading data...') }}</p>
				</div>

				<div v-if="!loading && currentSection === 'tree'">
					<h2>{{ t('approval', 'File Approval Status Tree') }}</h2>
					<ApprovalFileTree
						v-if="fileTreeWithKpis.length"
						:tree-data="fileTreeWithKpis"
						:workflows="workflows"
						@approve-file="handleApproveFile"
						@reject-file="handleRejectFile"
						@view-file="handleViewFile" />
					<p v-else>{{ t('approval', 'No files found in the approval system.') }}</p>
				</div>

				<div v-if="!loading && currentSection === 'kpis'">
					<h2>{{ t('approval', 'Workflow KPIs') }}</h2>
					<table v-if="workflowKpis.length" class="kpi-table">
						<thead>
							<tr>
								<th>{{ t('approval', 'Workflow') }}</th>
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
					<p v-else>{{ t('approval', 'No workflow KPI data available.') }}</p>
				</div>
			</div>
		</NcAppContent>
	</div>
</template>

<script>
import { NcAppContent, NcAppNavigation, NcAppNavigationItem, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import ApprovalFileTree from '../components/ApprovalFileTree.vue'
import { approve, reject } from '../files/helpers'

const STATUS_PENDING = 1
const STATUS_APPROVED = 2
const STATUS_REJECTED = 3

export default {
	name: 'ApprovalCenterView',
	components: {
		NcAppContent,
		NcAppNavigation,
		NcAppNavigationItem,
		NcLoadingIcon,
		ApprovalFileTree,
	},
	data() {
		return {
			loading: true,
			allApprovalFiles: [], // Fetched from /all-approval-files
			workflows: [],
			workflowKpis: [],
			currentSection: 'tree', // Default to tree view
		}
	},
	computed: {
		fileTreeWithKpis() {
			const tree = []
			const map = {}

			// Initialize tree structure and map all nodes
			this.allApprovalFiles.forEach(file => {
				const pathParts = file.path.split('/').filter(p => p !== '')
				let currentLevel = tree
				let currentPath = ''

				pathParts.forEach((part, index) => {
					currentPath += '/' + part
					let existingNode = map[currentPath]

					if (!existingNode) {
						if (index === pathParts.length - 1) { // File node
							existingNode = {
								name: part,
								type: 'file',
								path: currentPath,
								originalFile: file, // Includes status_code, rule_id etc.
								kpis: { pending: 0, approved: 0, rejected: 0 },
							}
							// For files, their own status contributes to their KPI (as a leaf node)
							if (file.status_code === STATUS_PENDING) existingNode.kpis.pending = 1
							else if (file.status_code === STATUS_APPROVED) existingNode.kpis.approved = 1
							else if (file.status_code === STATUS_REJECTED) existingNode.kpis.rejected = 1
						} else { // Folder node
							existingNode = {
								name: part,
								type: 'folder',
								path: currentPath,
								children: [],
								kpis: { pending: 0, approved: 0, rejected: 0 }, // Initialize KPIs for folders
								expanded: true,
							}
						}
						map[currentPath] = existingNode
						currentLevel.push(existingNode)
					}
					if (existingNode.type === 'folder') {
						currentLevel = existingNode.children
					}
				})
			})

			// Recursive function to calculate KPIs upwards
			const calculateFolderKpis = (folderNode) => {
				folderNode.kpis = { pending: 0, approved: 0, rejected: 0 } // Reset before summing
				folderNode.children.forEach(child => {
					if (child.type === 'folder') {
						calculateFolderKpis(child) // Recurse for subfolders
					}
					folderNode.kpis.pending += child.kpis.pending
					folderNode.kpis.approved += child.kpis.approved
					folderNode.kpis.rejected += child.kpis.rejected
				})
			}

			// Calculate KPIs for all top-level folders
			tree.filter(node => node.type === 'folder').forEach(calculateFolderKpis)

			return tree
		},
	},
	async mounted() {
		await this.reloadData()
	},
	methods: {
		async reloadData() {
			this.loading = true
			await this.fetchAllApprovalFiles()
			await this.fetchWorkflows()
			await this.fetchWorkflowKpis()
			this.loading = false
		},
		async fetchAllApprovalFiles() {
			try {
				const response = await axios.get(generateUrl('/apps/approval/all-approval-files'))
				this.allApprovalFiles = response.data || []
			} catch (e) {
				console.error(e)
				showError(t('approval', 'Could not load all approval files data'))
			}
		},
		async fetchWorkflows() {
			try {
				const response = await axios.get(generateUrl('/apps/approval/rules'))
				this.workflows = response.data || []
			} catch (e) {
				console.error(e)
				showError(t('approval', 'Could not load workflows'))
			}
		},
		async fetchWorkflowKpis() {
			try {
				const response = await axios.get(generateUrl('/apps/approval/workflow-kpis'))
				this.workflowKpis = response.data || []
			} catch (e) {
				console.error(e)
				showError(t('approval', 'Could not load workflow KPIs'))
			}
		},
		async handleApproveFile(file) {
			try {
				await approve(file.file_id, file.file_name, null, true) // originalFile is passed
				showSuccess(t('approval', 'File "{fileName}" approved.', { fileName: file.file_name }))
				await this.reloadData()
			} catch (err) {
				console.error('Error approving file:', err)
				showError(t('approval', 'Could not approve file "{fileName}".', { fileName: file.file_name }))
			}
		},
		async handleRejectFile(file) {
			try {
				await reject(file.file_id, file.file_name, null, true) // originalFile is passed
				showSuccess(t('approval', 'File "{fileName}" rejected.', { fileName: file.file_name }))
				await this.reloadData()
			} catch (err) {
				console.error('Error rejecting file:', err)
				showError(t('approval', 'Could not reject file "{fileName}".', { fileName: file.file_name }))
			}
		},
		handleViewFile(file) {
			const url = generateUrl(`/f/${file.file_id}`)
			window.open(url, '_blank')
		},
	},
}
</script>

<style scoped lang="scss">
.app-content-container {
	padding: 20px;
	height: 100%;
	overflow-y: auto;
}

.kpi-table {
	width: 100%;
	border-collapse: collapse;
	margin-top: 20px;

	th, td {
		border: 1px solid var(--color-border);
		padding: 8px 12px;
		text-align: left;
	}

	th {
		background-color: var(--color-background-hover);
	}
}
</style> 