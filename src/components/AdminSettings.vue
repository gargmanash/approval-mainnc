<!--
  - SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<div id="approval_prefs" class="section">
			<h2>
				<span class="icon icon-approval" />
				{{ t('approval', 'Approval workflows') }}
			</h2>
			<br>
			<p class="settings-hint">
				{{ t('approval', 'Each workflow defines who (which users, groups or circles) can approve files for a given pending tag and which approved/rejected tag should then be assigned.') }}
			</p>
			<p class="settings-hint">
				{{ t('approval', 'A list of users/groups/circles who can manually request approval can be optionally defined.') }}
			</p>
			<p class="settings-hint">
				{{ t('approval', 'To be considered approved, a file/directory having multiple pending tags assigned must be approved by all the workflows involved.') }}
			</p>
			<p class="settings-hint">
				{{ t('approval', 'You can chain approval workflows by using a pending tag as approved/rejected tag in another workflow.') }}
			</p>
			<p class="settings-hint">
				{{ t('approval', 'All tags must be different in a workflow. A pending tag can only be used in one workflow.') }}
			</p>
			<div v-if="showRules"
				class="rules">
				<ApprovalRule v-for="(rule, id) in rules"
					:key="id"
					v-model="rules[id]"
					class="approval-rule"
					delete-icon="icon-delete"
					@input="onRuleInput(id, $event)"
					@add-tag="onAddTagClick">
					<template #extra-buttons>
						<NcButton
							type="error"
							@click="onRuleDelete(id)">
							<template #icon>
								<DeleteIcon :size="20" />
							</template>
							{{ t('approval', 'Delete workflow') }}
						</NcButton>
					</template>
				</ApprovalRule>
				<NcEmptyContent v-if="noRules && !loadingRules"
					:title="t('approval', 'No workflow yet')"
					class="no-rules">
					<template #icon>
						<CheckIcon />
					</template>
				</NcEmptyContent>
				<div v-if="newRule" class="new-rule">
					<ApprovalRule
						v-model="newRule"
						:delete-rule-label="newRuleDeleteLabel"
						:focus="true"
						@add-tag="onAddTagClick">
						<template #extra-buttons>
							<NcButton
								@click="onNewRuleDelete">
								{{ newRuleDeleteLabel }}
							</NcButton>
							<NcButton
								type="success"
								:disabled="!newRuleIsValid"
								@click="onValidateNewRule">
								<template #icon>
									<CheckIcon :size="20" />
								</template>
								{{ createTooltip }}
							</NcButton>
						</template>
						<template #extra-footer>
							<p v-if="!newRuleIsValid"
								class="new-rule-error">
								{{ invalidRuleMessage }}
							</p>
						</template>
					</ApprovalRule>
				</div>
			</div>
			<NcButton :class="{ 'add-rule': true, loading: savingRule }"
				:disabled="savingRule"
				@click="onAddRule">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('approval', 'New workflow') }}
			</NcButton>
			<div class="create-tag">
				<label for="create-tag-input">
					<TagIcon :size="16" />
					{{ t('approval', 'Create new hidden tag') }}
				</label>
				<input id="create-tag-input"
					ref="createTagInput"
					v-model="newTagName"
					:placeholder="t('approval', 'New tag name')"
					type="text"
					@keyup.enter="onCreateTag">
				<NcButton :class="{ loading: creatingTag }"
					:disabled="creatingTag"
					@click="onCreateTag">
					<template #icon>
						<PlusIcon :size="20" />
					</template>
					{{ t('approval', 'Create') }}
				</NcButton>
			</div>
		</div>
		<div class="reset-section">
			<h3>{{ t('approval', 'Reset File Approval Statuses') }}</h3>
			<p class="warning-text">
				{{ t('approval', 'This will clear all pending, approved, and rejected statuses from all files, effectively resetting their approval history. Existing workflow definitions will NOT be affected.') }}
			</p>
			<p class="warning-text">
				{{ t('approval', 'Note: System tags (pending, approved, rejected) on files will not be automatically removed. You may need to manually clean these up for a complete visual reset in the Files app.') }}
			</p>
			<NcButton type="warning" @click="confirmResetActivity">
				{{ t('approval', 'Reset File Statuses Only') }}
			</NcButton>
		</div>
		<div class="reset-section destructive-reset">
			<h3>{{ t('approval', 'Reset All Approval Data (includes Workflows)') }}</h3>
			<p class="warning-text">
				{{ t('approval', 'Warning: This will permanently delete ALL defined approval workflows, ALL approval activity history, and ALL associations between files and workflows. This action cannot be undone.') }}
			</p>
			<p class="warning-text">
				{{ t('approval', 'System tags created by this app will not be automatically deleted. You may need to manually clean them up from the general system tag management settings if desired.') }}
			</p>
			<NcButton type="destructive" @click="confirmResetAllData">
				{{ t('approval', 'Reset All Data') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import CheckIcon from 'vue-material-design-icons/Check.vue'
import TagIcon from 'vue-material-design-icons/Tag.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'

import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'

import ApprovalRule from './ApprovalRule.vue'

import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { showSuccess, showError, getDialogBuilder } from '@nextcloud/dialogs'
import { translate } from '@nextcloud/l10n'

export default {
	name: 'AdminSettings',

	components: {
		CheckIcon,
		TagIcon,
		PlusIcon,
		DeleteIcon,
		ApprovalRule,
		NcEmptyContent,
		NcButton,
	},

	props: [],

	data() {
		return {
			showRules: true,
			newTagName: '',
			rules: {},
			newRule: null,
			creatingTag: false,
			savingRule: false,
			loadingRules: false,
			newRuleDeleteLabel: translate('approval', 'Cancel'),
		}
	},

	computed: {
		noRules() {
			return Object.keys(this.rules).length === 0
		},
		newRuleIsValid() {
			return !this.invalidRuleMessage
		},
		invalidRuleMessage() {
			const newRule = this.newRule
			const noMissingField = newRule.description
				&& newRule.tagPending
				&& newRule.tagApproved
				&& newRule.tagRejected
				&& newRule.approvers.length > 0
			if (!noMissingField) {
				return translate('approval', 'All fields are required')
			}

			if (newRule.tagPending === newRule.tagApproved
				|| newRule.tagPending === newRule.tagRejected
				|| newRule.tagApproved === newRule.tagRejected) {
				return translate('approval', 'All tags must be different')
			}

			const conflictingRule = Object.keys(this.rules).find((id) => {
				return this.rules[id].tagPending === newRule.tagPending
			})
			if (conflictingRule) {
				return translate('approval', 'Pending tag is already used in another workflow')
			}

			return null
		},
		createTooltip() {
			return translate('approval', 'Create workflow')
		},
	},

	watch: {
	},

	mounted() {
		this.loadRules()
	},

	methods: {
		translate,
		loadRules() {
			this.loadingRules = true
			const url = generateUrl('/apps/approval/rules')
			axios.get(url).then((response) => {
				this.rules = response.data
				// add unique ids to approvers/requesters values
				for (const id in this.rules) {
					this.rules[id].approvers = this.rules[id].approvers.map(a => {
						return {
							...a,
							trackKey: a.type + '-' + a.entityId,
						}
					})
					this.rules[id].requesters = this.rules[id].requesters.map(r => {
						return {
							...r,
							trackKey: r.type + '-' + r.entityId,
						}
					})
				}
			}).catch((error) => {
				showError(
					translate('approval', 'Failed to get approval workflows')
					+ ': ' + (error.response?.data?.error ?? error.response?.request?.responseText ?? ''),
				)
				console.error(error)
			}).then(() => {
				this.loadingRules = false
			})
		},
		onRuleInput(id, rule) {
			// save if all values are set
			if (rule.description && rule.tagPending && rule.tagApproved && rule.tagRejected && rule.approvers.length > 0) {
				this.savingRule = true
				const req = {
					tagPending: rule.tagPending,
					tagApproved: rule.tagApproved,
					tagRejected: rule.tagRejected,
					description: rule.description,
					approvers: rule.approvers.map((u) => {
						return {
							type: u.type,
							entityId: u.entityId,
						}
					}),
					requesters: rule.requesters.map((u) => {
						return {
							type: u.type,
							entityId: u.entityId,
						}
					}),
				}
				const url = generateUrl('/apps/approval/rule/' + id)
				axios.put(url, req).then((response) => {
					showSuccess(translate('approval', 'Approval workflow saved'))
				}).catch((error) => {
					showError(
						translate('approval', 'Failed to save approval workflow')
						+ ': ' + (error.response?.data?.error ?? error.response?.request?.responseText ?? ''),
					)
					console.error(error)
					// restore rule values
					this.rules[id] = rule.backupRule
				}).then(() => {
					this.savingRule = false
				})
			}
		},
		onAddRule() {
			this.newRule = {
				tagPending: 0,
				tagApproved: 0,
				tagRejected: 0,
				description: '',
				approvers: [],
				requesters: [],
			}
		},
		onNewRuleDelete() {
			this.newRule = null
		},
		onValidateNewRule() {
			const rule = this.newRule
			if (rule.tagPending && rule.tagApproved && rule.tagRejected && rule.approvers.length > 0) {
				this.savingRule = true
				// create
				const req = {
					tagPending: rule.tagPending,
					tagApproved: rule.tagApproved,
					tagRejected: rule.tagRejected,
					description: rule.description,
					approvers: rule.approvers.map((u) => {
						return {
							type: u.type,
							entityId: u.entityId,
						}
					}),
					requesters: rule.requesters.map((u) => {
						return {
							type: u.type,
							entityId: u.entityId,
						}
					}),
				}
				const url = generateUrl('/apps/approval/rule')
				axios.post(url, req).then((response) => {
					showSuccess(translate('approval', 'New approval workflow created'))
					const id = response.data
					this.newRule = null
					this.$set(this.rules, id, rule)
				}).catch((error) => {
					showError(
						translate('approval', 'Failed to create approval workflow')
						+ ': ' + (error.response?.data?.error ?? error.response?.request?.responseText ?? ''),
					)
					console.error(error)
				}).then(() => {
					this.savingRule = false
				})
			}
		},
		onRuleDelete(id) {
			const url = generateUrl('/apps/approval/rule/' + id)
			axios.delete(url).then((response) => {
				showSuccess(translate('approval', 'Approval workflow deleted'))
				this.$delete(this.rules, id)
			}).catch((error) => {
				showError(
					translate('approval', 'Failed to delete approval workflow')
					+ ': ' + (error.response?.data?.error ?? error.response?.request?.responseText ?? ''),
				)
				console.error(error)
			}).then(() => {
			})
		},
		onCreateTag() {
			if (this.newTagName) {
				this.creatingTag = true
				const req = {
					name: this.newTagName,
				}
				const url = generateUrl('/apps/approval/tag')
				axios.post(url, req).then((response) => {
					showSuccess(translate('approval', 'Tag "{name}" created', { name: this.newTagName }))
					this.newTagName = ''
					// trick to reload tag list
					this.showRules = false
					this.$nextTick(() => {
						this.showRules = true
					})
				}).catch((error) => {
					showError(
						translate('approval', 'Failed to create tag "{name}"', { name: this.newTagName })
						+ ': ' + (error.response?.data?.error ?? error.response?.request?.responseText ?? ''),
					)
					console.error(error)
				}).then(() => {
					this.creatingTag = false
				})
			}
		},
		onAddTagClick() {
			this.$refs.createTagInput.focus()
		},
		async confirmResetAllData() {
			const builder = getDialogBuilder(
				translate('approval', 'Reset All Approval Data'),
				translate('approval', 'Are you sure you want to permanently delete all approval workflows, activity, and history? This action cannot be undone.'),
			)
			builder.addButton({
				label: translate('approval', 'Cancel'),
				callback: (dialog) => { dialog.hide() },
			})
			builder.addButton({
				label: translate('approval', 'Reset All Data'),
				callback: async (dialog) => {
					try {
						await axios.post(generateUrl('/apps/approval/settings/reset-all-data'))
						showSuccess(translate('approval', 'All approval data has been successfully reset.'))
						this.loadRules() // Reload to show empty state
					} catch (e) {
						console.error('Error resetting approval data:', e)
						showError(translate('approval', 'Failed to reset approval data. Please check server logs.'))
					}
					dialog.hide()
				},
				style: builder.BUTTON_STYLE_DESTRUCTIVE,
			})
			builder.build().show()
		},
		async confirmResetActivity() {
			const builder = getDialogBuilder(
				translate('approval', 'Reset File Approval Statuses'),
				translate('approval', 'Are you sure you want to clear all current approval statuses and history for all files? Workflow definitions will remain. System tags on files will not be removed automatically.'),
			)
			builder.addButton({
				label: translate('approval', 'Cancel'),
				callback: (dialog) => { dialog.hide() },
			})
			builder.addButton({
				label: translate('approval', 'Reset File Statuses'),
				callback: async (dialog) => {
					try {
						await axios.post(generateUrl('/apps/approval/settings/reset-activity'))
						showSuccess(translate('approval', 'All file approval statuses and history have been reset.'))
					} catch (e) {
						console.error('Error resetting approval activity:', e)
						showError(translate('approval', 'Failed to reset approval activity. Please check server logs.'))
					}
					dialog.hide()
				},
				style: builder.BUTTON_STYLE_DESTRUCTIVE,
			})
			builder.build().show()
		},
	},
}
</script>

<style scoped lang="scss">
#approval_prefs {
	.rules {
		margin-top: 20px;
		display: flex;
		flex-wrap: wrap;
	}

	.icon {
		display: inline-block;
		width: 32px;
	}

	.settings-hint {
		.icon {
			margin-bottom: -3px;
		}
		.icon-error {
			padding: 11px 20px;
			vertical-align: text-bottom;
			opacity: 0.5;
		}
	}

	button .icon {
		width: unset;
	}

	.create-tag {
		margin-top: 30px;
		display: flex;
		align-items: center;

		> * {
			margin: 0 4px;
		}

		> label {
			display: flex;
			align-items: center;
			> * {
				margin: 0 4px;
			}
		}

		#create-tag-input {
			margin-inline-start: 3px;
		}
	}

	button.add-rule {
		margin: 0 0 10px 15px;
	}

	.approval-rule,
	.new-rule {
		margin: 15px 15px 15px 15px;
		width: min-content;
		height: min-content;
	}
	.new-rule {
		display: flex;
		align-items: center;
		>button {
			width: 36px;
			min-width: 36px;
			height: 36px;
			padding: 0;
			margin: 0 0 0 5px;
		}
		.new-rule-ok {
			width: max-content;
			margin: 0;
		}
		.new-rule-error {
			margin-top: 16px;
			color: var(--color-text-maxcontrast);
		}
	}
	.no-rules {
		margin-top: 0;
		width: 300px;
	}
}

.icon-approval {
	background-image: url('../../img/app-dark.svg');
	background-size: 23px 23px;
	height: 23px;
	margin-bottom: -4px;
	filter: var(--background-invert-if-dark);
}

body.theme--dark .icon-approval {
	background-image: url('../../img/app.svg');
}

.reset-section {
	margin-top: 40px;
	padding-top: 20px;
	border-top: 1px solid var(--color-border-darker);

	h3 {
		color: var(--color-error);
	}

	.warning-text {
		color: var(--color-text-maxcontrast);
		font-style: italic;
		margin-bottom: 10px;
	}
}

.destructive-reset {
	border-top: 1px dashed var(--color-border-maxcontrast) !important;
	margin-top: 20px !important;
}
</style>
