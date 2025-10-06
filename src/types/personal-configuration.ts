export interface PersonalConfiguration {
	account_connected: '0' | '1'
	account_id?: string
	account_charset?: string
	account_secret?: string
	account_server?: string
	account_provider?: string
	account_harmonization_start?: number
	account_harmonization_end?: number
	contacts_prevalence?: string
	contacts_harmonize?: boolean
	contacts_actions_local?: string
	contacts_actions_remote?: string
	events_prevalence?: string
	events_harmonize?: boolean
	events_actions_local?: string
	events_actions_remote?: string
	tasks_prevalence?: string
	tasks_harmonize?: boolean
	tasks_actions_local?: string
	tasks_actions_remote?: string
	system_contacts: number
	system_events: number
	system_tasks: number
	system_ms365_authorization_uri?: string
	system_approved_account_servers?: string[]
}
