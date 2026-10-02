import {
	IAuthenticateGeneric,
	ICredentialTestRequest,
	ICredentialType,
	INodeProperties,
} from 'n8n-workflow';

/**
 * WaDesk API credential — a workspace REST API key (wsk_...) plus the workspace
 * base URL. Both are shown in the app on the "n8n" page (Automation) and the
 * "Developers / API keys" page. Every request is authenticated with a bearer
 * token; the credential test hits GET /me.
 */
export class WaDeskApi implements ICredentialType {
	name = 'waDeskApi';

	displayName = 'WaDesk API';

	// Filled in per install — points at your own /developers/docs page.
	documentationUrl = '';

	properties: INodeProperties[] = [
		{
			displayName: 'Base URL',
			name: 'baseUrl',
			type: 'string',
			default: '',
			required: true,
			placeholder: 'https://app.example.com/api/v1',
			description:
				'Your workspace API base URL, ending in /api/v1. Copy it from the n8n page inside the app.',
		},
		{
			displayName: 'API Key',
			name: 'apiKey',
			type: 'string',
			typeOptions: { password: true },
			default: '',
			required: true,
			description: 'Workspace API key (starts with wsk_). Create it on the Developers / API keys page.',
		},
	];

	// Sends "Authorization: Bearer <apiKey>" on every request made with this
	// credential (both nodes rely on this).
	authenticate: IAuthenticateGeneric = {
		type: 'generic',
		properties: {
			headers: {
				Authorization: '=Bearer {{$credentials.apiKey}}',
			},
		},
	};

	// n8n's "Test" button on the credential → GET {baseUrl}/me.
	test: ICredentialTestRequest = {
		request: {
			baseURL: '={{$credentials.baseUrl}}',
			url: '/me',
			method: 'GET',
		},
	};
}
