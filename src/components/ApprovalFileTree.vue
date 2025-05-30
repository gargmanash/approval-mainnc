<template>
	<div class="approval-file-tree">
		<ul class="tree-level">
			<li v-for="item in treeData" :key="item.path" :class="{'is-folder': item.type === 'folder'}">
				<div v-if="item.type === 'folder'" class="tree-item-label" @click="toggleFolder(item)">
					<FolderOpenIcon v-if="item.expanded" :size="20" />
					<FolderIcon v-else :size="20" />
					<span class="item-name">{{ item.name }}</span>
					<span v-if="item.kpis" class="folder-kpis">
						(P: {{ item.kpis.pending }}, A: {{ item.kpis.approved }}, R: {{ item.kpis.rejected }})
					</span>
				</div>
				<div v-else-if="item.type === 'file'" class="file-row">
					<span>{{ item.name }}</span>
					<span class="item-rule">({{ getRuleDescription(item.originalFile.rule_id) }})</span>
				</div>

				<ApprovalFileTree
					v-if="item.type === 'folder' && item.expanded && item.children && item.children.length"
					:key="item.path + '-' + item.expanded"
					:tree-data="item.children"
					:workflows="workflows"
					@approve-file="$emit('approve-file', $event)"
					@reject-file="$emit('reject-file', $event)"
					@view-file="$emit('view-file', $event)"
					@toggle-expand="$emit('toggle-expand', $event)" />
			</li>
		</ul>
	</div>
</template>

<script>
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import FolderOpenIcon from 'vue-material-design-icons/FolderOpen.vue'
import { translate } from '@nextcloud/l10n'

const STATUS_PENDING = 1
const STATUS_APPROVED = 2
const STATUS_REJECTED = 3

export default {
	name: 'ApprovalFileTree',
	components: {
		FolderIcon,
		FolderOpenIcon,
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
	emits: ['approve-file', 'reject-file', 'view-file', 'toggle-expand'],
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
				this.$emit('toggle-expand', item)
			}
		},
		getMimeIcon(mimetype) {
			return OC.MimeType.getIconUrl(mimetype)
		},
		getRuleDescription(ruleId) {
			const rule = this.workflows.find(w => w.id === ruleId)
			return rule ? rule.description : this.t('approval', 'Unknown Rule')
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
				flex-shrink: 0;
			}

			.item-name {
				font-weight: normal;
				white-space: nowrap;
				flex-shrink: 0;
			}

			.folder-kpis,
			.item-rule {
				margin-inline-start: 8px;
				font-size: 0.9em;
				color: var(--color-text-maxcontrast-secondary);
				white-space: nowrap;
				flex-shrink: 0;
			}
		}

		&.is-folder > .tree-item-label .item-name {
			font-weight: bold;
		}

		.file-row {
			display: flex;
			align-items: center;
			margin-inline-start: 28px;
			margin-top: 4px;

			> span:first-child {
				white-space: nowrap;
				flex-shrink: 0;
			}

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
