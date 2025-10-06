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
import {computed, onMounted, reactive, type Ref, ref} from 'vue'
import axios from '@nextcloud/axios'
import {loadState} from '@nextcloud/initial-state'
import {showError, showSuccess} from '@nextcloud/dialogs'
import {translate as t} from '@nextcloud/l10n'

import {
	NcActions,
	NcActionButton,
	NcActionRadio,
	NcButton,
	NcCheckboxRadioSwitch,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'

import EwsIcon from './icons/EwsIcon.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import ContactIcon from 'vue-material-design-icons/ContactsOutline.vue'
import LinkIcon from 'vue-material-design-icons/Link.vue'

import {
	APP_ID,
	type AuthProviderOption,
	authProviderOptions,
	generateAppUrl
} from '../utils';
import type {
	Correlation,
	Collection,
	PersonalConfiguration,
	CorrelationType
} from "../types";

// Data
const state = reactive<PersonalConfiguration>(loadState(APP_ID, 'personal-configuration'))

// collections
const availableRemoteContactCollections = ref<Collection[]>([])
const availableLocalContactCollections = ref<Collection[]>([])
const establishedContactCorrelations = ref<Correlation[]>([])

// calendars
const availableRemoteEventCollections = ref<Collection[]>([])
const availableLocalEventCollections = ref<Collection[]>([])
const establishedEventCorrelations = ref<Correlation[]>([])

// tasks
const availableRemoteTaskCollections = ref<Collection[]>([])
const availableLocalTaskCollections = ref<Collection[]>([])
const establishedTaskCorrelations = ref<Correlation[]>([])

const configureManually = ref<boolean>(!!(state.account_server ?? ''))
const configureMail = ref<boolean>(false)

const approvedAccountServersCount = computed((): number => {
	return state.system_approved_account_servers?.length ?? 0
})

const useApprovedAccountServers = computed((): boolean => {
	return approvedAccountServersCount.value > 0;
})

if (useApprovedAccountServers.value) {
	if (approvedAccountServersCount.value === 1) {
		state.account_server = state.system_approved_account_servers?.[0]
	}
	configureManually.value = true;
}

// Methods
const fetchPreferences = async () => {
	try {
		const uri = generateAppUrl('/fetch-preferences')
		const response = await axios.get(uri)
		if (response.data) {
			Object.assign(state, response.data)
		}
	} catch (error: any) {
		showError(t(APP_ID, 'Failed to retrieve preferences') + ': ' + error.response?.request?.responseText)
	}
}

const loadData = () => {
	if (state.account_connected === '1') {
		fetchCorrelations()
		fetchLocalCollections()
		fetchRemoteCollections()
	}
}

const onConnectAlternateClick = async () => {
	try {
		const uri = generateAppUrl('/connect-alternate')
		const data = {
			params: {
				account_id: state.account_id,
				account_secret: state.account_secret,
				account_server: state.account_server,
				account_charset: state.account_charset,
				flag: configureMail.value,
			},
		}
		const response = await axios.get(uri, data)
		if (response.data === 'success') {
			showSuccess('Successfully connected to EWS account')
			state.account_connected = '1'
			await fetchPreferences()
			loadData()
		}
	} catch (error: any) {
		showError(t(APP_ID, 'Failed to authenticate with EWS server') + ': ' + error.response?.request?.responseText)
	}
}

const onConnectMS365Click = () => {
	const ssoWindow = window.open(
		state.system_ms365_authorization_uri,
		t(APP_ID, 'Sign in Nextcloud EWS Connector'),
		' width=600, height=700'
	)
	ssoWindow?.focus()
	window.addEventListener('message', () => {
		state.account_connected = '1'
		fetchPreferences()
		loadData()
	})
}

const onDisconnectClick = async () => {
	try {
		const uri = generateAppUrl('/disconnect')
		await axios.get(uri)
		showSuccess('Successfully disconnected from EWS account')
		state.account_connected = '0'
		await fetchPreferences()
		availableRemoteContactCollections.value = []
		availableLocalContactCollections.value = []
		establishedContactCorrelations.value = []
		availableRemoteEventCollections.value = []
		availableLocalEventCollections.value = []
		establishedEventCorrelations.value = []
		availableRemoteTaskCollections.value = []
		availableLocalTaskCollections.value = []
		establishedTaskCorrelations.value = []
	} catch (error: any) {
		showError(t(APP_ID, 'Failed to disconnect from EWS account') + ': ' + error.response?.request?.responseText)
	}
}

const depositPreferences = async (values: Partial<PersonalConfiguration>) => {
	try {
		const data = {values}
		const uri = generateAppUrl('/deposit-preferences')
		await axios.put(uri, data)
		showSuccess(t(APP_ID, 'Saved preferences'))
	} catch (error: any) {
		showError(t(APP_ID, 'Failed to save preferences') + ': ' + error.response?.request?.responseText)
	}
}

const depositCorrelations = async () => {
	try {
		const uri = generateAppUrl('/deposit-correlations')
		const data = {
			ContactCorrelations: establishedContactCorrelations.value,
			EventCorrelations: establishedEventCorrelations.value,
			TaskCorrelations: establishedTaskCorrelations.value,
		}
		const response = await axios.put(uri, data)
		showSuccess('Saved correlations')
		if (response.data.ContactCorrelations) {
			establishedContactCorrelations.value = response.data.ContactCorrelations
			showSuccess(`Found ${establishedContactCorrelations.value.length} Contact Collection Correlations`)
		}
		if (response.data.EventCorrelations) {
			establishedEventCorrelations.value = response.data.EventCorrelations
			showSuccess(`Found ${establishedEventCorrelations.value.length} Event Collection Correlations`)
		}
		if (response.data.TaskCorrelations) {
			establishedTaskCorrelations.value = response.data.TaskCorrelations
			showSuccess(`Found ${establishedTaskCorrelations.value.length} Task Collection Correlations`)
		}
	} catch (error: any) {
		showError(t(APP_ID, 'Failed to save correlations') + ': ' + error.response?.request?.responseText)
	}
}

const onSaveClick = async () => {
	await depositPreferences({
		contacts_prevalence: state.contacts_prevalence,
		contacts_harmonize: state.contacts_harmonize,
		contacts_actions_local: state.contacts_actions_local,
		contacts_actions_remote: state.contacts_actions_remote,
		events_prevalence: state.events_prevalence,
		events_harmonize: state.events_harmonize,
		events_actions_local: state.events_actions_local,
		events_actions_remote: state.events_actions_remote,
		tasks_prevalence: state.tasks_prevalence,
		tasks_harmonize: state.tasks_harmonize,
		tasks_actions_local: state.tasks_actions_local,
		tasks_actions_remote: state.tasks_actions_remote,
	})
	await depositCorrelations()
}

const onHarmonizeClick = async () => {
	try {
		const uri = generateAppUrl('/harmonize')
		await axios.get(uri)
		showSuccess('Synchronization Successful')
	} catch (error: any) {
		showError(t(APP_ID, 'Synchronization Failed') + ': ' + error.response?.request?.responseText)
	}
}

const fetchRemoteCollections = async () => {
	try {
		const uri = generateAppUrl('/fetch-remote-collections')
		const response = await axios.get(uri)
		if (response.data.ContactCollections) {
			availableRemoteContactCollections.value = response.data.ContactCollections
			showSuccess(`Found ${availableRemoteContactCollections.value.length} Remote Contacts Collections`)
		}
		if (response.data.EventCollections) {
			availableRemoteEventCollections.value = response.data.EventCollections
			showSuccess(`Found ${availableRemoteEventCollections.value.length} Remote Events Collections`)
		}
		if (response.data.TaskCollections) {
			availableRemoteTaskCollections.value = response.data.TaskCollections
			showSuccess(`Found ${availableRemoteTaskCollections.value.length} Remote Tasks Collections`)
		}
	} catch (error: any) {
		showError(t(APP_ID, 'Failed to load remote collections list') + ': ' + error.response?.request?.responseText)
	}
}

const fetchLocalCollections = async () => {
	try {
		const uri = generateAppUrl('/fetch-local-collections')
		const response = await axios.get(uri)
		if (response.data.ContactCollections) {
			availableLocalContactCollections.value = response.data.ContactCollections
			showSuccess(`Found ${availableLocalContactCollections.value.length} Local Contacts Collections`)
		}
		if (response.data.EventCollections) {
			availableLocalEventCollections.value = response.data.EventCollections
			showSuccess(`Found ${availableLocalEventCollections.value.length} Local Events Collections`)
		}
		if (response.data.TaskCollections) {
			availableLocalTaskCollections.value = response.data.TaskCollections
			showSuccess(`Found ${availableLocalTaskCollections.value.length} Local Tasks Collections`)
		}
	} catch (error: any) {
		showError(t(APP_ID, 'Failed to load local collections list') + ': ' + error.response?.request?.responseText)
	}
}

const fetchCorrelations = async () => {
	try {
		const uri = generateAppUrl('/fetch-correlations')
		const response = await axios.get(uri)
		if (response.data.ContactCorrelations) {
			establishedContactCorrelations.value = response.data.ContactCorrelations
			showSuccess(`Found ${establishedContactCorrelations.value.length} Contact Collection Correlations`)
		}
		if (response.data.EventCorrelations) {
			establishedEventCorrelations.value = response.data.EventCorrelations
			showSuccess(`Found ${establishedEventCorrelations.value.length} Event Collection Correlations`)
		}
		if (response.data.TaskCorrelations) {
			establishedTaskCorrelations.value = response.data.TaskCorrelations
			showSuccess(`Found ${establishedTaskCorrelations.value.length} Task Collection Correlations`)
		}
	} catch (error: any) {
		showError(t(APP_ID, 'Failed to load collection correlations list') + ': ' + error.response?.request?.responseText)
	}
}

const changeCorrelation = (correlationsArray: Ref<Correlation[]>, roid: string, loid: string, type: CorrelationType) => {
	const cid = correlationsArray.value.findIndex(i => String(i.roid) === String(roid));

	if (cid === -1) {
		correlationsArray.value.push({
			id: null,
			roid,
			loid,
			type,
			action: 'C'
		});
	} else if (correlationsArray.value[cid]) {
		correlationsArray.value[cid].loid = loid;
		correlationsArray.value[cid].action = 'U';
	} else {
		// TODO: raise error
	}
};

const changeContactCorrelation = (roid: string, loid: string, value?: boolean) => {
	changeCorrelation(establishedContactCorrelations, roid, loid, 'CC');
}

const changeEventCorrelation = (roid: string, loid: string, value?: boolean) => {
	changeCorrelation(establishedEventCorrelations, roid, loid, 'EC');
}

const changeTaskCorrelation = (roid: string, loid: string, value?: boolean) => {
	changeCorrelation(establishedTaskCorrelations, roid, loid, 'TC');
}

const clearCorrelation = (correlationsArray: Ref<Correlation[]>, roid: string): void => {
	const cid = correlationsArray.value.findIndex((i: any) => String(i.roid) === String(roid));

	if (cid > -1) {
		if (correlationsArray.value[cid]) {
			correlationsArray.value[cid].roid = null;
			correlationsArray.value[cid].loid = null;
			correlationsArray.value[cid].action = 'D';
		} else {
			//TODO: Raise error
		}
	}
};

const clearContactCorrelation = (roid: string): void => {
	clearCorrelation(establishedContactCorrelations, roid);
}

const clearEventCorrelation = (roid: string): void => {
	clearCorrelation(establishedEventCorrelations, roid);
}

const clearTaskCorrelation = (roid: string): void => {
	clearCorrelation(establishedTaskCorrelations, roid);
}

const establishedContactCorrelationDisable = (roid: string, loid: string): boolean => {
	const citem = establishedContactCorrelations.value.find(i => String(i.loid) === String(loid))
	return typeof citem !== 'undefined' && citem.roid !== roid
}

const establishedContactCorrelationSelect = (roid: string, loid: string): boolean => {
	const citem = establishedContactCorrelations.value.find(i => String(i.loid) === String(loid))
	return typeof citem !== 'undefined' && citem.roid === roid
}

const establishedEventCorrelationDisable = (roid: string, loid: string): boolean => {
	const citem = establishedEventCorrelations.value.find(i => String(i.loid) === String(loid))
	return typeof citem !== 'undefined' && citem.roid !== roid
}

const establishedEventCorrelationSelect = (roid: string, loid: string): boolean => {
	const citem = establishedEventCorrelations.value.find(i => String(i.loid) === String(loid))
	return typeof citem !== 'undefined' && citem.roid === roid
}

const establishedTaskCorrelationDisable = (roid: string, loid: string): boolean => {
	const citem = establishedTaskCorrelations.value.find(i => String(i.loid) === String(loid))
	return typeof citem !== 'undefined' && citem.roid !== roid
}

const establishedTaskCorrelationSelect = (roid: string, loid: string): boolean => {
	const citem = establishedTaskCorrelations.value.find(i => String(i.loid) === String(loid))
	return typeof citem !== 'undefined' && citem.roid === roid
}

const formatDate = (dt: number): string => {
	if (dt) {
		return (new Date(dt * 1000)).toLocaleString()
	}
	return 'never'
}

// Lifecycle hook
onMounted(() => {
	loadData()
})
</script>

<template>
	<div id="ews_settings" class="section">
		<div class="ews-page-title">
			<EwsIcon :size="32"/>
			<h2>{{ t(APP_ID, 'Exchange EWS Connector') }}</h2>
		</div>
		<div v-if="state.account_connected !== '1'"
			 class="ews-section-connect">
			<h3>{{ t(APP_ID, 'Authentication') }}</h3>
			<div class="setting-row">
				<label for="ews-auth-provider">
					{{ t(APP_ID, 'Provider ') }}
				</label>
				<NcSelect input-id="ews-auth-provider"
						  v-model="state.account_provider"
						  :reduce="(item:AuthProviderOption) => item.id"
						  :label-outside="true"
						  :options="authProviderOptions"/>
			</div>
			<div v-if="state.account_provider == 'MS365'"
				 class="setting-row">
				<div v-if="state.system_ms365_authorization_uri === ''">
					{{
						t(APP_ID, 'Microsoft Exchange 365 configuration missing. Ask your Nextcloud administrator to configure Microsoft Exchange 365 in the EWS Connector section in the administration section.')
					}}
				</div>
				<div v-else>
					<div class="description">
						{{
							t(APP_ID, 'Press connect and enter your account information')
						}}
					</div>
					<div class="actions">
						<NcButton @click="onConnectMS365Click">
							<template #icon>
								<CheckIcon/>
							</template>
							{{ t(APP_ID, 'Connect') }}
						</NcButton>
					</div>
				</div>
			</div>
			<div v-else class="ews-section-connect-alternate">
				<div class="description">
					{{
						t(APP_ID, 'Enter your Exchange Server and account information then press connect.')
					}}
				</div>
				<div class="setting-row">
					<label for="ews-account-id">
						<EwsIcon/>
						{{ t(APP_ID, 'Account ID') }}
					</label>
					<NcTextField id="ews-account-id"
								 v-model="state.account_id"
								 type="text"
								 :placeholder="t(APP_ID, 'Authentication Id for your EWS Account')"
								 :label-outside="true"
								 autocomplete="off"
								 autocorrect="off"
								 autocapitalize="none"
								 :style="{ width: '48ch' }"/>
				</div>
				<div class="setting-row">
					<label for="ews-account-secret">
						<EwsIcon/>
						{{ t(APP_ID, 'Account Secret') }}
					</label>
					<NcTextField id="ews-account-secret"
								 v-model="state.account_secret"
								 type="password"
								 :placeholder="t(APP_ID, 'Authentication Secret for your EWS Account')"
								 :label-outside="true"
								 autocomplete="off"
								 autocorrect="off"
								 autocapitalize="none"
								 :style="{ width: '48ch' }"/>
				</div>
				<div v-if="configureManually" class="setting-row">
					<label for="ews-server">
						<EwsIcon/>
						{{ t(APP_ID, 'Account Server') }}
					</label>
					<NcTextField id="ews-server"
								 v-if="!useApprovedAccountServers || approvedAccountServersCount === 1"
								 v-model="state.account_server"
								 type="text"
								 :placeholder="t(APP_ID, 'Account Server IP or FQDN (server.example.com)')"
								 :label-outside="true"
								 autocomplete="off"
								 autocorrect="off"
								 autocapitalize="none"
								 :readonly="state.system_approved_account_servers?.length === 1"
								 :style="{ width: '48ch' }"/>
					<NcSelect id="ews-server"
							  v-else
							  v-model="state.account_server"
							  :options="state.system_approved_account_servers"/>
				</div>
				<div class="setting-row" v-if="!useApprovedAccountServers">
					<NcCheckboxRadioSwitch v-model="configureManually"
										   type="switch">
						{{
							t(APP_ID, 'Configure server manually')
						}}
					</NcCheckboxRadioSwitch>
				</div>
				<div class="setting-row">
					<NcCheckboxRadioSwitch v-model="configureMail"
										   type="switch">
						{{
							t(APP_ID, 'Configure mail app on successful connection')
						}}
					</NcCheckboxRadioSwitch>
				</div>
				<div class="actions">
					<NcButton @click="onConnectAlternateClick">
						<template #icon>
							<CheckIcon/>
						</template>
						{{ t(APP_ID, 'Connect') }}
					</NcButton>
				</div>
			</div>
		</div>
		<div v-else>
			<h3>{{ t(APP_ID, 'Connection') }}</h3>
			<div class="setting-row">
				<EwsIcon/>
				<label>
					{{
						t(APP_ID, 'Connected to {account_id} at {account_server}', {
							'account_id': state.account_id ?? '',
							'account_server': state.account_server ?? ''
						})
					}}
				</label>
				<NcButton @click="onDisconnectClick">
					<template #icon>
						<CloseIcon/>
					</template>
					{{ t(APP_ID, 'Disconnect') }}
				</NcButton>
			</div>
			<div class="setting-row">
				{{ t(APP_ID, 'Synchronization was last started on ') }}
				{{ formatDate(state.account_harmonization_start ?? 0) }}
				{{ t(APP_ID, 'and finished on ') }}
				{{ formatDate(state.account_harmonization_end ?? 0) }}
			</div>
			<h3>{{ t(APP_ID, 'Contacts') }}</h3>
			<div class="correlations-contacts">
				<div class="description">
					{{
						t(APP_ID, 'Select the remote contacts folder(s) you wish to synchronize by pressing the link button next to the contact folder name and selecting the local contacts address book to synchronize to.')
					}}
				</div>
				<div v-if="state.system_contacts == 1">
					<ul v-if="availableRemoteContactCollections.length > 0">
						<li v-for="ritem in availableRemoteContactCollections"
							:key="ritem.id" class="setting-row">
							<ContactIcon/>
							<label>
								{{ ritem.name }} ({{ ritem.count }}
								Contacts)
							</label>
							<NcActions>
								<template #icon>
									<LinkIcon/>
								</template>
								<NcActionButton
									@click="clearContactCorrelation(ritem.id)">
									<template #icon>
										<CloseIcon/>
									</template>
									Clear
								</NcActionButton>
								<NcActionRadio
									v-for="litem in availableLocalContactCollections"
									:key="litem.id"
									:name="`available-local-contact-collections-${litem.id}`"
									:disabled="establishedContactCorrelationDisable(ritem.id, litem.id)"
									:model-value="establishedContactCorrelationSelect(ritem.id, litem.id) ? 1 : 0"
									:value="1"
									@update:model-value="changeContactCorrelation(ritem.id, litem.id)">
									{{ litem.name }}
								</NcActionRadio>
							</NcActions>
						</li>
					</ul>
					<div
						v-else-if="availableRemoteContactCollections.length == 0">
						{{
							t(APP_ID, 'No contacts collections where found in the connected account.')
						}}
					</div>
					<div v-else>
						{{
							t(APP_ID, 'Loading contacts collections from the connected account.')
						}}
					</div>
					<div>
						<label>
							{{ t(APP_ID, 'Synchronize ') }}
						</label>
						<NcSelect v-model="state.contacts_harmonize"
								  :reduce="item => item.id"
								  :options="[{label: 'Never', id: '-1'}, {label: 'Manually', id: '0'}, {label: 'Automatically', id: '5'}]"/>
						<label>
							{{
								t(APP_ID, 'and if there is a conflict')
							}}
						</label>
						<NcSelect v-model="state.contacts_prevalence"
								  :reduce="item => item.id"
								  :options="[{label: 'Remote', id: 'R'}, {label: 'Local', id: 'L'}, {label: 'Chronology', id: 'C'}]"/>
						<label>
							{{ t(APP_ID, 'prevails') }}
						</label>
					</div>
					<div v-if="false" style="display: flex">
						<label>
							{{
								t(APP_ID, 'Syncronized these local actions to the Remote system')
							}}
						</label>
						<NcCheckboxRadioSwitch
							v-model="state.contacts_actions_local"
							value="c" name="contacts_actions_local">
							Create
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							v-model="state.contacts_actions_local"
							value="u" name="contacts_actions_local">
							Update
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							v-model="state.contacts_actions_local"
							value="d" name="contacts_actions_local">
							Delete
						</NcCheckboxRadioSwitch>
					</div>
					<div v-if="false" style="display: flex">
						<label>
							{{
								t(APP_ID, 'Syncronized these remote actions to the local system')
							}}
						</label>
						<NcCheckboxRadioSwitch
							v-model="state.contacts_actions_remote"
							value="c" name="contacts_actions_remote">
							Create
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							v-model="state.contacts_actions_remote"
							value="u" name="contacts_actions_remote">
							Update
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							v-model="state.contacts_actions_remote"
							value="d" name="contacts_actions_remote">
							Delete
						</NcCheckboxRadioSwitch>
					</div>
				</div>
				<div v-else>
					{{
						t(APP_ID, 'The contacts app is either disabled or not installed. Please contact your administrator to install or enable the app.')
					}}
				</div>
			</div>
			<h3>{{ t(APP_ID, 'Calendars') }}</h3>
			<div class="correlations-events">
				<div class="description">
					{{
						t(APP_ID, 'Select the remote calendar(s) you wish to synchronize by pressing the link button next to the calendars name and selecting the local calendar to synchronize to.')
					}}
				</div>
				<div v-if="state.system_events == 1">
					<ul v-if="availableRemoteEventCollections.length > 0">
						<li v-for="ritem in availableRemoteEventCollections"
							:key="ritem.id" class="setting-row">
							<CalendarIcon/>
							<label>
								{{ ritem.name }} ({{ ritem.count }} Events)
							</label>
							<NcActions>
								<template #icon>
									<LinkIcon/>
								</template>
								<NcActionButton
									@click="clearEventCorrelation(ritem.id)">
									<template #icon>
										<CloseIcon/>
									</template>
									Clear
								</NcActionButton>
								<NcActionRadio
									v-for="litem in availableLocalEventCollections"
									:key="litem.id"
									:name="`available-local-event-collections-${litem.id}`"
									:disabled="establishedEventCorrelationDisable(ritem.id, litem.id)"
									:model-value="establishedEventCorrelationSelect(ritem.id, litem.id) ? 1 : 0"
									:value="1"
									@update:model-value="changeEventCorrelation(ritem.id, litem.id)">
									{{ litem.name }}
								</NcActionRadio>
							</NcActions>
						</li>
					</ul>
					<div
						v-else-if="availableRemoteEventCollections.length == 0">
						{{
							t(APP_ID, 'No events collections where found in the connected account.')
						}}
					</div>
					<div v-else>
						{{
							t(APP_ID, 'Loading events collections from the connected account.')
						}}
					</div>
					<div>
						<label>
							{{ t(APP_ID, 'Synchronize ') }}
						</label>
						<NcSelect v-model="state.events_harmonize"
								  :reduce="item => item.id"
								  :options="[{label: 'Never', id: '-1'}, {label: 'Manually', id: '0'}, {label: 'Automatically', id: '5'}]"/>
						<label>
							{{
								t(APP_ID, 'and if there is a conflict')
							}}
						</label>
						<NcSelect v-model="state.events_prevalence"
								  :reduce="item => item.id"
								  :options="[{label: 'Remote', id: 'R'}, {label: 'Local', id: 'L'}, {label: 'Chronology', id: 'C'}]"/>
						<label>
							{{ t(APP_ID, 'prevails') }}
						</label>
					</div>
					<div v-if="false" style="display: flex">
						<label>
							{{
								t(APP_ID, 'Syncronized these local actions to the Remote system')
							}}
						</label>
						<NcCheckboxRadioSwitch
							v-model="state.events_actions_local"
							value="c" name="events_actions_local">
							Create
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							v-model="state.events_actions_local"
							value="u" name="events_actions_local">
							Update
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							v-model="state.events_actions_local"
							value="d" name="events_actions_local">
							Delete
						</NcCheckboxRadioSwitch>
					</div>
					<div v-if="false" style="display: flex">
						<label>
							{{
								t(APP_ID, 'Syncronized these remote actions to the local system')
							}}
						</label>
						<NcCheckboxRadioSwitch
							v-model="state.events_actions_remote"
							value="c" name="events_actions_remote">
							Create
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							v-model="state.events_actions_remote"
							value="u" name="events_actions_remote">
							Update
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							v-model="state.events_actions_remote"
							value="d" name="events_actions_remote">
							Delete
						</NcCheckboxRadioSwitch>
					</div>
				</div>
				<div v-else>
					{{
						t(APP_ID, 'The calendar app is either disabled or not installed. Please contact your administrator to install or enable the app.')
					}}
				</div>
			</div>
			<h3>{{ t(APP_ID, 'Tasks') }}</h3>
			<div class="correlations-tasks">
				<div class="description">
					{{
						t(APP_ID, 'Select the remote Task(s) folder you wish to synchronize by pressing the link button next to the folder name and selecting the local calendar to synchronize to.')
					}}
				</div>
				<div v-if="state.system_tasks == 1">
					<ul v-if="availableRemoteTaskCollections.length > 0">
						<li v-for="ritem in availableRemoteTaskCollections"
							:key="ritem.id" class="setting-row">
							<CalendarIcon/>
							<label>
								{{ ritem.name }} ({{ ritem.count }} Tasks)
							</label>
							<NcActions>
								<template #icon>
									<LinkIcon/>
								</template>
								<NcActionButton
									@click="clearTaskCorrelation(ritem.id)">
									<template #icon>
										<CloseIcon/>
									</template>
									Clear
								</NcActionButton>
								<NcActionRadio
									v-for="litem in availableLocalTaskCollections"
									:key="litem.id"
									:name="`available-local-task-collections-${litem.id}`"
									:disabled="establishedTaskCorrelationDisable(ritem.id, litem.id)"
									:model-value="establishedTaskCorrelationSelect(ritem.id, litem.id) ? 1 : 0"
									:value="1"
									@change="changeTaskCorrelation(ritem.id, litem.id)">
									{{ litem.name }}
								</NcActionRadio>
							</NcActions>
						</li>
					</ul>
					<div
						v-else-if="availableRemoteTaskCollections.length == 0">
						{{
							t(APP_ID, 'No tasks collections where found in the connected account.')
						}}
					</div>
					<div v-else>
						{{
							t(APP_ID, 'Loading tasks collections from the connected account.')
						}}
					</div>
					<div>
						<label>
							{{ t(APP_ID, 'Synchronize ') }}
						</label>
						<NcSelect v-model="state.tasks_harmonize"
								  :reduce="item => item.id"
								  :options="[{label: 'Never', id: '-1'}, {label: 'Manually', id: '0'}, {label: 'Automatically', id: '5'}]"/>
						<label>
							{{
								t(APP_ID, 'and if there is a conflict')
							}}
						</label>
						<NcSelect v-model="state.tasks_prevalence"
								  :reduce="item => item.id"
								  :options="[{label: 'Remote', id: 'R'}, {label: 'Local', id: 'L'}, {label: 'Chronology', id: 'C'}]"/>
						<label>
							{{ t(APP_ID, 'prevails') }}
						</label>
					</div>
					<div v-if="false" style="display: flex">
						<label>
							{{
								t(APP_ID, 'Synchronized these local actions to the Remote system')
							}}
						</label>
						<NcCheckboxRadioSwitch
							v-model="state.tasks_actions_local"
							value="c" name="tasks_actions_local">
							Create
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							v-model="state.tasks_actions_local"
							value="u" name="tasks_actions_local">
							Update
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							v-model="state.tasks_actions_local"
							value="d" name="tasks_actions_local">
							Delete
						</NcCheckboxRadioSwitch>
					</div>
					<div v-if="false" style="display: flex">
						<label>
							{{
								t(APP_ID, 'Syncronized these remote actions to the local system')
							}}
						</label>
						<NcCheckboxRadioSwitch
							v-model="state.tasks_actions_remote"
							value="c" name="tasks_actions_remote">
							Create
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							v-model="state.tasks_actions_remote"
							value="u" name="tasks_actions_remote">
							Update
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							v-model="state.tasks_actions_remote"
							value="d" name="tasks_actions_remote">
							Delete
						</NcCheckboxRadioSwitch>
					</div>
				</div>
				<div v-else>
					{{
						t(APP_ID, 'The tasks app is either disabled or not installed. Please contact your administrator to install or enable the app.')
					}}
				</div>
			</div>
			<div class="actions">
				<NcButton @click="onSaveClick()">
					<template #icon>
						<CheckIcon/>
					</template>
					{{ t(APP_ID, 'Save') }}
				</NcButton>
				<NcButton @click="onHarmonizeClick()">
					<template #icon>
						<LinkIcon/>
					</template>
					{{ t(APP_ID, 'Sync') }}
				</NcButton>
			</div>
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
