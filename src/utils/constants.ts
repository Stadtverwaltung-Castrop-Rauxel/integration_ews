import { translate as t } from '@nextcloud/l10n'

export const APP_ID = 'integration_ews'

export type AuthProviderId = 'A' | 'MS365';

export interface AuthProviderOption {
	id: string,
	label: string
}

export const authProviderOptions: AuthProviderOption[] = [
	{
		label: t(APP_ID, 'On-Premises / Alternate'),
		id: 'A',
	},
	{
		label: t(APP_ID, 'Microsoft Exchange 365 Online'),
		id: 'MS365',
	},
]
