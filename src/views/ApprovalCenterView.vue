<template>
	<div id="approval-center-view">
		<NcAppContent app-name="approval">
			<template #app-navigation>
				<NcAppNavigation :title="t('approval', 'Approval Center')" />
			</template>

			<div class="split-layout">
				<div class="left-pane">
					<h2>{{ t('approval', 'File Approval Status Tree') }}</h2>
					<ApprovalFileTree
						v-if="fileTreeWithKpis.length"
						:tree-data="fileTreeWithKpis"
						:workflows="workflows"
						@approve-file="handleApproveFile"
						@reject-file="handleRejectFile"
						@view-file="handleViewFile"
						@toggle-expand="handleToggleExpand" />
					<p v-else>
						{{ t('approval', 'No files found in the approval system.') }}
					</p>
				</div>
				<div class="right-pane">
					<ApprovalAnalytics />
				</div>
			</div>
		</NcAppContent>
	</div>
</template>

<script>
import { NcAppContent, NcAppNavigation } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import ApprovalFileTree from '../components/ApprovalFileTree.vue'
import ApprovalAnalytics from './ApprovalAnalytics.vue'
import { approve, reject } from '../files/helpers.js'
import { translate } from '@nextcloud/l10n'

const STATUS_PENDING = 1
const STATUS_APPROVED = 2
const STATUS_REJECTED = 3

export default {
	name: 'ApprovalCenterView',
	components: {
		NcAppContent,
		NcAppNavigation,
		ApprovalFileTree,
		ApprovalAnalytics,
	},
	data() {
		return {
			loading: true,
			allApprovalFiles: [], // Fetched from /all-approval-files
			workflows: [],
			workflowKpis: [],
			expandedFolderStates: {}, // Added for managing folder expanded states
		}
	},
	computed: {
		fileTreeWithKpis() {
			// eslint-disable-next-line no-console
			console.log('[ApprovalCenterView] computed fileTreeWithKpis: input allApprovalFiles:', JSON.parse(JSON.stringify(this.allApprovalFiles)))
			const tree = []
			const map = {}

			// Build tree: folders and files as siblings, files only under their true parent
			this.allApprovalFiles.forEach(file => {
				const pathParts = file.path.split('/').filter(p => p !== '')
				let currentLevel = tree
				let currentPath = ''
				let parentNode = null

				// Traverse all but the last part (which is the file)
				for (let i = 0; i < pathParts.length - 1; i++) {
					const part = pathParts[i]
					currentPath += '/' + part
					let existingNode = map[currentPath]
					if (!existingNode) {
						existingNode = {
							name: part,
							type: 'folder',
							path: currentPath,
							children: [],
							kpis: { pending: 0, approved: 0, rejected: 0 },
							expanded: !!this.expandedFolderStates[currentPath],
						}
						map[currentPath] = existingNode
						currentLevel.push(existingNode)
					}
					parentNode = existingNode
					currentLevel = existingNode.children
				}

				// Now add the file node as a child of the last folder
				const fileName = pathParts[pathParts.length - 1]
				const filePath = currentPath + '/' + fileName
				if (!map[filePath]) {
					const fileNode = {
						name: fileName,
						type: 'file',
						path: filePath,
						originalFile: file,
						kpis: { pending: 0, approved: 0, rejected: 0 },
					}
					if (file.status_code === STATUS_PENDING) fileNode.kpis.pending = 1
					else if (file.status_code === STATUS_APPROVED) fileNode.kpis.approved = 1
					else if (file.status_code === STATUS_REJECTED) fileNode.kpis.rejected = 1
					if (parentNode && parentNode.children) {
						// Prevent duplicate file nodes
						if (!parentNode.children.some(child => child.type === 'file' && child.name === fileName)) {
							parentNode.children.push(fileNode)
						}
					} else {
						// Prevent duplicate file nodes at root
						if (!tree.some(child => child.type === 'file' && child.name === fileName)) {
							tree.push(fileNode)
						}
					}
					map[filePath] = fileNode
				}
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

			// If the tree has a single root node (typically the username like 'manas' or 'admin'),
			// and that node itself is a folder and contains a direct child folder named 'files',
			// then we display the contents of that 'files' folder as the root of the tree.
			// This effectively hides the top-level username folder and the 'files' folder itself.
			if (tree.length === 1) {
				const topLevelNode = tree[0]
				// Ensure topLevelNode is a folder and has children to search
				if (topLevelNode.type === 'folder' && topLevelNode.children && topLevelNode.children.length > 0) {
					const filesNode = topLevelNode.children.find(child => child.type === 'folder' && child.name === 'files')

					if (filesNode && filesNode.children) {
						// eslint-disable-next-line no-console
						console.log(`[ApprovalCenterView] Root node is '${topLevelNode.name}'. Found 'files' child. Returning contents of '${topLevelNode.name}/files' as the new root.`)
						return filesNode.children
					} else {
						// eslint-disable-next-line no-console
						console.log(`[ApprovalCenterView] Root node is '${topLevelNode.name}', but a 'files' subdirectory was not found or is empty. Displaying tree from '${topLevelNode.name}'.`)
					}
				} else if (topLevelNode.type === 'folder' && (!topLevelNode.children || topLevelNode.children.length === 0)) {
					// eslint-disable-next-line no-console
					console.log(`[ApprovalCenterView] Root node '${topLevelNode.name}' is an empty folder. Displaying it as root.`)
				} else if (topLevelNode.type === 'file') {
					// eslint-disable-next-line no-console
					console.log(`[ApprovalCenterView] Root node '${topLevelNode.name}' is a file. Displaying it as root.`)
				}
			}

			// eslint-disable-next-line no-console
			console.log('[ApprovalCenterView] computed fileTreeWithKpis: output tree (original or un-modified root):', JSON.parse(JSON.stringify(tree)))
			return tree
		},
	},
	async mounted() {
		// eslint-disable-next-line no-console
		console.log('[ApprovalCenterView] mounted hook called.')
		await this.reloadData()
		// eslint-disable-next-line no-console
		console.log('[ApprovalCenterView] mounted: after reloadData() - loading:', this.loading)
		// eslint-disable-next-line no-console
		console.log('[ApprovalCenterView] mounted: allApprovalFiles.length:', this.allApprovalFiles.length)
		// eslint-disable-next-line no-console
		console.log('[ApprovalCenterView] mounted: fileTreeWithKpis.length (computed):', this.fileTreeWithKpis.length)
		// eslint-disable-next-line no-console
		console.log('[ApprovalCenterView] mounted: workflowKpis.length:', this.workflowKpis.length)
	},
	methods: {
		t: translate,
		handleToggleExpand(itemToToggle) {
			// eslint-disable-next-line no-console
			console.log('[ApprovalCenterView] handleToggleExpand received for path:', itemToToggle.path)
			const currentExpandedState = this.expandedFolderStates[itemToToggle.path] || false
			this.$set(this.expandedFolderStates, itemToToggle.path, !currentExpandedState)
			// eslint-disable-next-line no-console
			console.log(`[ApprovalCenterView] Path '${itemToToggle.path}' new expanded state: ${this.expandedFolderStates[itemToToggle.path]}`)
		},
		async reloadData() {
			// eslint-disable-next-line no-console
			console.log('[ApprovalCenterView] reloadData: starting...')
			this.loading = true
			try {
				await this.fetchAllApprovalFiles()
				await this.fetchWorkflows()
				await this.fetchWorkflowKpis()
			} finally {
				this.loading = false
				// eslint-disable-next-line no-console
				console.log('[ApprovalCenterView] reloadData: finished. loading:', this.loading)
				// eslint-disable-next-line no-console
				console.log('[ApprovalCenterView] reloadData: allApprovalFiles.length:', this.allApprovalFiles.length)
				// Note: Accessing computed property here will trigger its calculation if not already cached
				// eslint-disable-next-line no-console
				console.log('[ApprovalCenterView] reloadData: fileTreeWithKpis.length (computed):', this.fileTreeWithKpis.length)
				// eslint-disable-next-line no-console
				console.log('[ApprovalCenterView] reloadData: workflowKpis.length:', this.workflowKpis.length)
			}
		},
		async fetchAllApprovalFiles() {
			// eslint-disable-next-line no-console
			console.log('[ApprovalCenterView] fetchAllApprovalFiles: fetching...')
			try {
				const response = await axios.get(generateUrl('/apps/approval/all-approval-files'))
				// eslint-disable-next-line no-console
				console.log('[ApprovalCenterView] fetchAllApprovalFiles: response.data:', JSON.parse(JSON.stringify(response.data)))
				this.allApprovalFiles = response.data || []
				// eslint-disable-next-line no-console
				console.log('[ApprovalCenterView] fetchAllApprovalFiles: this.allApprovalFiles set, length:', this.allApprovalFiles.length)
			} catch (e) {
				// eslint-disable-next-line no-console
				console.error('[ApprovalCenterView] fetchAllApprovalFiles: error', e)
				showError(translate('approval', 'Could not load all approval files data'))
			}
		},
		async fetchWorkflows() {
			// eslint-disable-next-line no-console
			console.log('[ApprovalCenterView] fetchWorkflows: fetching...')
			try {
				const response = await axios.get(generateUrl('/apps/approval/rules'))
				// eslint-disable-next-line no-console
				console.log('[ApprovalCenterView] fetchWorkflows: response.data:', JSON.parse(JSON.stringify(response.data)))
				this.workflows = response.data ? Object.values(response.data) : []
				// eslint-disable-next-line no-console
				console.log('[ApprovalCenterView] fetchWorkflows: this.workflows set, length:', this.workflows.length)
			} catch (e) {
				// eslint-disable-next-line no-console
				console.error('[ApprovalCenterView] fetchWorkflows: error', e)
				showError(translate('approval', 'Could not load workflows'))
			}
		},
		async fetchWorkflowKpis() {
			// eslint-disable-next-line no-console
			console.log('[ApprovalCenterView] fetchWorkflowKpis: fetching...')
			try {
				const response = await axios.get(generateUrl('/apps/approval/workflow-kpis'))
				// eslint-disable-next-line no-console
				console.log('[ApprovalCenterView] fetchWorkflowKpis: response.data:', JSON.parse(JSON.stringify(response.data)))
				this.workflowKpis = response.data || []
				// eslint-disable-next-line no-console
				console.log('[ApprovalCenterView] fetchWorkflowKpis: this.workflowKpis set, length:', this.workflowKpis.length)
			} catch (e) {
				// eslint-disable-next-line no-console
				console.error('[ApprovalCenterView] fetchWorkflowKpis: error', e)
				showError(translate('approval', 'Could not load workflow KPIs'))
			}
		},
		async handleApproveFile(file) {
			try {
				await approve(file.file_id, file.file_name, null, true) // originalFile is passed
				showSuccess(translate('approval', 'File "{fileName}" approved.', { fileName: file.file_name }))
				await this.reloadData()
			} catch (err) {
				console.error('Error approving file:', err)
				showError(translate('approval', 'Could not approve file "{fileName}".', { fileName: file.file_name }))
			}
		},
		async handleRejectFile(file) {
			try {
				await reject(file.file_id, file.file_name, null, true) // originalFile is passed
				showSuccess(translate('approval', 'File "{fileName}" rejected.', { fileName: file.file_name }))
				await this.reloadData()
			} catch (err) {
				console.error('Error rejecting file:', err)
				showError(translate('approval', 'Could not reject file "{fileName}".', { fileName: file.file_name }))
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
.split-layout {
	display: flex;
	flex-direction: row;
	gap: 24px;
	width: 100%;
}

.left-pane {
	flex: 1 1 0;
	min-width: 220px;
	max-width: 320px;
	background: var(--color-main-background);
	padding: 20px;
	border-radius: var(--border-radius);
	box-shadow: var(--box-shadow);
	height: fit-content;
}

.right-pane {
	flex: 4 1 0;
	background: var(--color-main-background);
	padding: 20px;
	border-radius: var(--border-radius);
	box-shadow: var(--box-shadow);
	height: fit-content;
	/* overflow-x: auto;  No longer needed */
}

h1, h2 {
	color: var(--color-main-text);
}

p {
	color: var(--color-text-maxcontrast);
}
</style>
