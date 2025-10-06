export interface AdminConfiguration {
	transport_verification: '0' | '1'
	transport_log: '0' | '1'
	transport_log_path: string
	harmonization_mode: 'A' | 'P'
	harmonization_thread_duration: number
	harmonization_thread_pause: number
	ms365_tenant_id: string
	ms365_application_id: string
	ms365_application_secret: string
	approved_account_servers: string[]
}
