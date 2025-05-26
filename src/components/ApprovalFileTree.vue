<template>
	<div class="approval-file-tree">
		<ul class="tree-level">
			<li v-for="item in treeData" :key="item.path" :class="{'is-folder': item.type === 'folder'}">
				<div class="tree-item-label" @click="toggleFolder(item)">
					<FolderOpenIcon v-if="item.type === 'folder' && item.expanded" :size="20" />
					<FolderIcon v-else-if="item.type === 'folder' && !item.expanded" :size="20" />
					<NcIconSvgWrapper v-else :icon="getMimeIcon(item.originalFile.mimetype)" :size="20" />
					<span class="item-name">{{ item.name }}</span>
					<span v-if="item.type === 'folder' && item.kpis" class="folder-kpis">
						(P: {{ item.kpis.pending }}, A: {{ item.kpis.approved }}, R: {{ item.kpis.rejected }})
					</span>
					<span v-else-if="item.type === 'file'" class="item-rule">
						({{ getRuleDescription(item.originalFile.rule_id) }})
					</span>
				</div>
				<div v-if="item.type === 'file' && item.originalFile.status_code === STATUS_PENDING" class="file-actions">
					<NcButton @click.stop="approveFile(item.originalFile)">
						{{ t('approval', 'Approve') }}
					</NcButton>
					<NcButton type="secondary" @click.stop="rejectFile(item.originalFile)">
						{{ t('approval', 'Reject') }}
					</NcButton>
					<NcButton type="tertiary" class="icon-only" @click.stop="viewFile(item.originalFile)">
						<template #icon>
							<OpenInNewIcon :size="20" />
						</template>
					</NcButton>
				</div>
				<div v-else-if="item.type === 'file'" class="file-status-indicator">
					<span v-if="item.originalFile.status_code === STATUS_APPROVED" class="status-approved">{{ t('approval', 'Approved') }}</span>
					<span v-else-if="item.originalFile.status_code === STATUS_REJECTED" class="status-rejected">{{ t('approval', 'Rejected') }}</span>
					<NcButton type="tertiary" class="icon-only" @click.stop="viewFile(item.originalFile)">
						<template #icon>
							<OpenInNewIcon :size="20" />
						</template>
					</NcButton>
				</div>

				<ApprovalFileTree
					v-if="item.type === 'folder' && item.expanded && item.children && item.children.length"
					:tree-data="item.children"
					:workflows="workflows"
					@approve-file="$emit('approve-file', $event)"
					@reject-file="$emit('reject-file', $event)"
					@view-file="$emit('view-file', $event)" />
			</li>
		</ul>
	</div>
</template>

<script>
import { NcButton, NcIconSvgWrapper } from '@nextcloud/vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import FolderOpenIcon from 'vue-material-design-icons/FolderOpen.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import { translate } from '@nextcloud/l10n'

const STATUS_PENDING = 1
const STATUS_APPROVED = 2
const STATUS_REJECTED = 3

export default {
	name: 'ApprovalFileTree',
	components: {
		NcButton,
		NcIconSvgWrapper,
		FolderIcon,
		FolderOpenIcon,
		OpenInNewIcon,
	},
	props: {
		treeData: {
			type: Array,
			required: true,
		},
		workflows: {
			type: Array,
			required: true,
		},
	},
	emits: ['approve-file', 'reject-file', 'view-file'],
	data() {
		return {
			STATUS_PENDING, // Expose to template
			STATUS_APPROVED,
			STATUS_REJECTED,
		}
	},
	methods: {
		t: translate,
		toggleFolder(item) {
			if (item.type === 'folder') {
				item.expanded = !item.expanded
			}
		},
		getMimeIcon(mimetype) {
			return OC.MimeType.getIconUrl(mimetype)
		},
		getRuleDescription(ruleId) {
			const rule = this.workflows.find(w => w.id === ruleId)
			return rule ? rule.description : t('approval', 'Unknown Rule')
		},
		approveFile(file) {
			this.$emit('approve-file', file)
		},
		rejectFile(file) {
			this.$emit('reject-file', file)
		},
		viewFile(file) {
			this.$emit('view-file', file)
		},
	},
}
</script>

<style scoped lang="scss">
.approval-file-tree {
	.tree-level {
		list-style: none;
		padding-inline-start: 20px;
	}

	li {
		padding: 5px 0;

		.tree-item-label {
			display: flex;
			align-items: center;
			cursor: pointer;

			.nc-icon-svg {
				margin-inline-end: 8px;
			}

			.item-name {
				font-weight: normal;
			}

			.folder-kpis,
			.item-rule {
				margin-inline-start: 8px;
				font-size: 0.9em;
				color: var(--color-text-maxcontrast-secondary);
			}
		}

		&.is-folder > .tree-item-label .item-name {
			font-weight: bold;
		}

		.file-actions,
		.file-status-indicator {
			display: flex;
			align-items: center;
			margin-inline-start: 28px;
			margin-top: 4px;

			.nc-button {
				margin-inline-end: 8px;
			}
			.status-approved {
				color: var(--color-success-default);
				margin-inline-end: 8px;
			}
			.status-rejected {
				color: var(--color-error-default);
				margin-inline-end: 8px;
			}
		}
	}
}
</style>
