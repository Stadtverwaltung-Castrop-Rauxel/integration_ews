<!--
*
* @copyright Copyright (c) 2023 Sebastian Krupinski <krupinski01@gmail.com>
*
* @author Sebastian Krupinski <krupinski01@gmail.com>
*
* @license AGPL-3.0-or-later
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License as
* published by the Free Software Foundation, either version 3 of the
* License, or (at your option) any later version.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
-->

<script setup lang="ts">
import {computed, reactive} from 'vue'
import axios from '@nextcloud/axios'
import {loadState} from '@nextcloud/initial-state'
import {showError, showSuccess} from '@nextcloud/dialogs'
import {translate as t} from '@nextcloud/l10n'

import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcSelect,
	NcTextField
} from '@nextcloud/vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'

import EwsIcon from './icons/EwsIcon.vue'

import {APP_ID, generateAppUrl} from "../utils"
import type {AdminConfiguration} from "../types";


const state = reactive<AdminConfiguration>(loadState(APP_ID, 'admin-configuration'))

const newApprovedAccountServer = reactive({
	server: "",
});

const transportVerification = computed({
	get() {
		return state.transport_verification === '1'
	},
	set(value: boolean) {
		state.transport_verification = value ? '1' : '0'
	},
})

const transportLog = computed({
	get() {
		return state.transport_log === '1'
	},
	set(value: boolean) {
		state.transport_log = value ? '1' : '0'
	},
})

// Validate required fields
const isValid = computed(() => newApprovedAccountServer.server.trim() !== "");

// Add a new item only if valid
function addItem() {
	if (!isValid.value) {
		return;
	}
	state.approved_account_servers.push(newApprovedAccountServer.server);
	newApprovedAccountServer.server = "";
}

// Remove item by index
function removeItem(index) {
	state.approved_account_servers.splice(index, 1);
}

const onSaveClick = async () => {
	const req = {
		values: {
			transport_verification: state.transport_verification,
			transport_log: state.transport_log,
			transport_log_path: state.transport_log_path,
			harmonization_mode: state.harmonization_mode,
			harmonization_thread_duration: state.harmonization_thread_duration,
			harmonization_thread_pause: state.harmonization_thread_pause,
			ms365_tenant_id: state.ms365_tenant_id,
			ms365_application_id: state.ms365_application_id,
			ms365_application_secret: state.ms365_application_secret,
			approved_account_servers: state.approved_account_servers,
		},
	}
	const url = generateAppUrl('/admin-configuration')
	try {
		await axios.put(url, req);
		showSuccess(t(APP_ID, 'EWS admin configuration saved'));
	} catch (error: any) {
		showError(
			t(APP_ID, 'Failed to save EWS admin configuration') +
			': ' + error.response?.request?.responseText
		);
	}
}
</script>

<template>
	<div id="ews_settings" class="section">
		<div class="ews-page-title">
			<EwsIcon :size="32"/>
			<h2>{{ t(APP_ID, 'Exchange EWS Connector') }}</h2>
		</div>
		<div class="section">
			<h3>{{t(APP_ID, 'Select the system settings for Exchange Integration') }}</h3>
			<div class="setting-row">
				<label for="ews-harmonization_mode">
					{{ t(APP_ID, 'Synchronization Mode') }}
				</label>
				<NcSelect input-id="ews-harmonization_mode"
						  v-model="state.harmonization_mode"
						  :reduce="item => item.id"
						  :options="[{label: 'Passive', id: 'P'}, {label: 'Active', id: 'A'}]"/>
			</div>
			<div v-if="state.harmonization_mode === 'A'" class="setting-row">
				<label for="ews-thread-duration">
					{{
						t(APP_ID, 'Synchronization Thread Duration')
					}}
				</label>
				<input id="ews-thread-duration"
					   v-model="state.harmonization_thread_duration"
					   type="text"
					   :autocomplete="'off'"
					   :autocorrect="'off'"
					   :autocapitalize="'none'">
				<label>
					{{ t(APP_ID, 'Seconds') }}
				</label>
			</div>
			<div v-if="state.harmonization_mode === 'A'" class="setting-row">
				<label for="ews-thread-pause">
					{{ t(APP_ID, 'Synchronization Thread Pause') }}
				</label>
				<input id="ews-thread-pause"
					   v-model="state.harmonization_thread_pause"
					   type="text"
					   autocomplete="off"
					   autocorrect="off"
					   autocapitalize="none">
				<label>
					{{ t(APP_ID, 'Seconds') }}
				</label>
			</div>
			<div class="setting-row">
				<NcCheckboxRadioSwitch v-model="transportVerification"
									   type="switch">
					{{
						t(APP_ID, 'Secure Transport Verification (SSL Certificate Verification). Should always be ON, unless connecting to a Exchange system over an internal LAN.')
					}}
				</NcCheckboxRadioSwitch>
			</div>
			<div class="setting-row">
				<NcCheckboxRadioSwitch v-model="transportLog" type="switch">
					{{ t(APP_ID, 'Enable Transport Logging') }}
				</NcCheckboxRadioSwitch>
			</div>
			<div v-if="state.transport_log === '1'" class="setting-row">
				<label for="ews-thread-pause">
					{{ t(APP_ID, 'Location of Transport Log') }}
				</label>
				<input id="ews-thread-pause"
					   v-model="state.transport_log_path"
					   type="text"
					   autocomplete="off"
					   autocorrect="off"
					   autocapitalize="none">
			</div>
		</div>
		<div class="section">
			<h3>{{t(APP_ID, 'Microsoft 365 Authentication Settings')}}</h3>
			<div class="setting-row">
				<label for="ews-microsoft-tenant-id">
					<EwsIcon/>
					{{ t(APP_ID, 'Tenant ID') }}
				</label>
				<input id="ews-microsoft-tenant-id"
					   v-model="state.ms365_tenant_id"
					   type="text"
					   :placeholder="t(APP_ID, '')"
					   autocomplete="off"
					   autocorrect="off"
					   autocapitalize="none"
					   :style="{ width: '48ch' }">
			</div>
			<div class="setting-row">
				<label for="ews-microsoft-application-id">
					<EwsIcon/>
					{{ t(APP_ID, 'Application ID') }}
				</label>
				<input id="ews-microsoft-application-id"
					   v-model="state.ms365_application_id"
					   type="text"
					   :placeholder="t(APP_ID, '')"
					   autocomplete="off"
					   autocorrect="off"
					   autocapitalize="none"
					   :style="{ width: '48ch' }">
			</div>
			<div class="setting-row">
				<label for="ews-microsoft-application-secret">
					<EwsIcon/>
					{{ t(APP_ID, 'Application Secret') }}
				</label>
				<input id="ews-microsoft-application-secret"
					   v-model="state.ms365_application_secret"
					   type="password"
					   :placeholder="t(APP_ID, '')"
					   autocomplete="off"
					   autocorrect="off"
					   autocapitalize="none"
					   :style="{ width: '48ch' }">
			</div>
		</div>
		<div class="section">
			<h3>{{t(APP_ID, 'Approved Account Servers') }}</h3>
			<div class="setting-row">
				<ul>
					<li
						v-for="(item, index) in state.approved_account_servers"
						:key="index"
						class="setting-row"
					>
						<div>
							<strong>{{ item }}</strong>
						</div>
						<NcButton
							@click="removeItem(index)"
						>✕
						</NcButton>
					</li>
					<li v-if="state.approved_account_servers.length === 0">
						{{
							t(APP_ID, 'No servers added yet. No restrictions applied')
						}}
					</li>
				</ul>
			</div>
			<div class="setting-row">
				<label for="new_approved_account_server">
					{{ t(APP_ID, 'New Approved Account Server') }}
				</label>
				<NcTextField id="new_approved_account_server"
							 v-model="newApprovedAccountServer.server"
							 @keydown.enter="addItem"
				/>
				<NcButton @click="addItem" :disabled="!isValid">
					{{ t(APP_ID, 'Add') }}
				</NcButton>
			</div>
		</div>
		<div class="section">
			<NcButton @click="onSaveClick()">
				<template #icon>
					<CheckIcon/>
				</template>
				{{ t(APP_ID, 'Save') }}
			</NcButton>
		</div>
	</div>
</template>

<style scoped lang="scss">
#ews_settings {
	.section {
		margin-bottom: 1.5em;
	}

	.setting-row {
		display: flex;
		align-items: center;
		gap: 1em;
		padding: 0.4em 0;
		//border-bottom: 1px solid var(--color-border);
	}

	.setting-row label {
		flex: 0 0 auto;
		font-weight: 500;
	}

	.setting-row button {
		flex: 0 0 auto;
	}

	.setting-row input,
	.setting-row select {
		flex: 1 1 auto;
		max-width: 250px;
	}

	.ews-page-title {
		display: flex;
		vertical-align: middle;
	}

	h3 {
		font-weight: bolder;
		font-size: larger;
	}
}
</style>
