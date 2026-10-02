import {
	IExecuteFunctions,
	ILoadOptionsFunctions,
	INodeExecutionData,
	INodePropertyOptions,
	INodeType,
	INodeTypeDescription,
	IDataObject,
	IHttpRequestMethods,
	IHttpRequestOptions,
	NodeConnectionType,
	NodeOperationError,
} from 'n8n-workflow';

/** Base URL from the credential, with any trailing slash removed. */
async function baseUrl(ctx: IExecuteFunctions | ILoadOptionsFunctions): Promise<string> {
	const creds = await ctx.getCredentials('waDeskApi');
	return String(creds.baseUrl || '').replace(/\/+$/, '');
}

/** One authenticated request against the workspace REST API. */
async function apiRequest(
	ctx: IExecuteFunctions | ILoadOptionsFunctions,
	method: IHttpRequestMethods,
	path: string,
	body?: IDataObject,
	qs?: IDataObject,
): Promise<any> {
	const options: IHttpRequestOptions = {
		method,
		url: (await baseUrl(ctx)) + path,
		json: true,
		headers: { Accept: 'application/json' },
	};
	if (body && Object.keys(body).length) options.body = body;
	if (qs && Object.keys(qs).length) options.qs = qs;
	return ctx.helpers.httpRequestWithAuthentication.call(ctx, 'waDeskApi', options);
}

export class WaDesk implements INodeType {
	description: INodeTypeDescription = {
		displayName: 'WaDesk',
		name: 'waDesk',
		icon: 'file:wadesk.svg',
		group: ['output'],
		version: 1,
		subtitle: '={{$parameter["operation"] + ": " + $parameter["resource"]}}',
		description: 'Send messages and manage contacts, templates and flows in WaDesk',
		defaults: { name: 'WaDesk' },
		inputs: ['main'] as NodeConnectionType[],
		outputs: ['main'] as NodeConnectionType[],
		credentials: [{ name: 'waDeskApi', required: true }],
		properties: [
			{
				displayName: 'Resource',
				name: 'resource',
				type: 'options',
				noDataExpression: true,
				options: [
					{ name: 'Message', value: 'message' },
					{ name: 'Contact', value: 'contact' },
					{ name: 'Template', value: 'template' },
					{ name: 'Flow', value: 'flow' },
				],
				default: 'message',
			},

			// ── Message ─────────────────────────────────────────────────────
			{
				displayName: 'Operation',
				name: 'operation',
				type: 'options',
				noDataExpression: true,
				displayOptions: { show: { resource: ['message'] } },
				options: [
					{
						name: 'Send',
						value: 'send',
						action: 'Send a message',
						description: 'Send a WhatsApp message (text or media)',
					},
				],
				default: 'send',
			},
			{
				displayName: 'To',
				name: 'to',
				type: 'string',
				required: true,
				default: '',
				placeholder: '919812345678',
				description: 'Recipient phone in international format (digits, E.164)',
				displayOptions: { show: { resource: ['message'] } },
			},
			{
				displayName: 'Type',
				name: 'type',
				type: 'options',
				options: [
					{ name: 'Text', value: 'text' },
					{ name: 'Image', value: 'image' },
					{ name: 'Video', value: 'video' },
					{ name: 'Document', value: 'document' },
					{ name: 'Audio', value: 'audio' },
					{ name: 'Location', value: 'location' },
				],
				default: 'text',
				displayOptions: { show: { resource: ['message'], operation: ['send'] } },
			},
			{
				displayName: 'Text',
				name: 'text',
				type: 'string',
				typeOptions: { rows: 3 },
				default: '',
				description: 'Message body (for text) or caption (for media)',
				displayOptions: {
					show: { resource: ['message'], operation: ['send'], type: ['text', 'image', 'video', 'document'] },
				},
			},
			{
				displayName: 'Media URL',
				name: 'mediaUrl',
				type: 'string',
				default: '',
				placeholder: 'https://example.com/file.jpg',
				description: 'Public URL of the media file to send',
				displayOptions: {
					show: { resource: ['message'], operation: ['send'], type: ['image', 'video', 'document', 'audio'] },
				},
			},
			{
				displayName: 'Latitude',
				name: 'latitude',
				type: 'number',
				default: 0,
				displayOptions: { show: { resource: ['message'], operation: ['send'], type: ['location'] } },
			},
			{
				displayName: 'Longitude',
				name: 'longitude',
				type: 'number',
				default: 0,
				displayOptions: { show: { resource: ['message'], operation: ['send'], type: ['location'] } },
			},
			{
				displayName: 'Sender Device ID',
				name: 'deviceId',
				type: 'string',
				default: '',
				description: 'Optional. A specific sender (device or provider). Leave empty for the workspace default.',
				displayOptions: { show: { resource: ['message'], operation: ['send'] } },
			},

			// ── Contact ─────────────────────────────────────────────────────
			{
				displayName: 'Operation',
				name: 'operation',
				type: 'options',
				noDataExpression: true,
				displayOptions: { show: { resource: ['contact'] } },
				options: [
					{ name: 'Create', value: 'create', action: 'Create a contact' },
				],
				default: 'create',
			},
			{
				displayName: 'Name',
				name: 'name',
				type: 'string',
				required: true,
				default: '',
				description: "Contact's full name",
				displayOptions: { show: { resource: ['contact'], operation: ['create'] } },
			},
			{
				displayName: 'Phone',
				name: 'phone',
				type: 'string',
				required: true,
				default: '',
				placeholder: '919812345678',
				description: 'Phone in international format (digits, E.164)',
				displayOptions: { show: { resource: ['contact'], operation: ['create'] } },
			},
			{
				displayName: 'Additional Fields',
				name: 'contactExtra',
				type: 'collection',
				placeholder: 'Add Field',
				default: {},
				displayOptions: { show: { resource: ['contact'], operation: ['create'] } },
				options: [
					{ displayName: 'Email', name: 'email', type: 'string', default: '' },
					{ displayName: 'Country Code', name: 'country_code', type: 'string', default: '', placeholder: '+91' },
					{ displayName: 'Language', name: 'language', type: 'string', default: '' },
					{ displayName: 'Note', name: 'note', type: 'string', default: '' },
				],
			},

			// ── Template ────────────────────────────────────────────────────
			{
				displayName: 'Operation',
				name: 'operation',
				type: 'options',
				noDataExpression: true,
				displayOptions: { show: { resource: ['template'] } },
				options: [
					{ name: 'Send', value: 'send', action: 'Send a template' },
				],
				default: 'send',
			},
			{
				displayName: 'Template Name or ID',
				name: 'templateId',
				type: 'options',
				typeOptions: { loadOptionsMethod: 'getTemplates' },
				required: true,
				default: '',
				description:
					'Choose a template. Choose from the list, or specify an ID using an expression.',
				displayOptions: { show: { resource: ['template'], operation: ['send'] } },
			},
			{
				displayName: 'To',
				name: 'to',
				type: 'string',
				required: true,
				default: '',
				placeholder: '919812345678',
				displayOptions: { show: { resource: ['template'], operation: ['send'] } },
			},
			{
				displayName: 'Body Variables',
				name: 'bodyVars',
				type: 'string',
				default: '',
				description:
					'Positional body variables for {{1}}, {{2}}, ... as a comma-separated list (e.g. John,12345)',
				displayOptions: { show: { resource: ['template'], operation: ['send'] } },
			},

			// ── Flow ────────────────────────────────────────────────────────
			{
				displayName: 'Operation',
				name: 'operation',
				type: 'options',
				noDataExpression: true,
				displayOptions: { show: { resource: ['flow'] } },
				options: [
					{ name: 'Enroll Contact', value: 'enroll', action: 'Enroll a contact in a flow' },
				],
				default: 'enroll',
			},
			{
				displayName: 'Flow Name or ID',
				name: 'flowId',
				type: 'options',
				typeOptions: { loadOptionsMethod: 'getFlows' },
				required: true,
				default: '',
				description: 'Choose a flow. Choose from the list, or specify an ID using an expression.',
				displayOptions: { show: { resource: ['flow'], operation: ['enroll'] } },
			},
			{
				displayName: 'Contact By',
				name: 'contactBy',
				type: 'options',
				options: [
					{ name: 'Phone', value: 'phone' },
					{ name: 'Contact ID', value: 'contact_id' },
				],
				default: 'phone',
				displayOptions: { show: { resource: ['flow'], operation: ['enroll'] } },
			},
			{
				displayName: 'Value',
				name: 'contactValue',
				type: 'string',
				required: true,
				default: '',
				description: 'The phone number or contact ID to enroll',
				displayOptions: { show: { resource: ['flow'], operation: ['enroll'] } },
			},
		],
	};

	methods = {
		loadOptions: {
			async getTemplates(this: ILoadOptionsFunctions): Promise<INodePropertyOptions[]> {
				const res = await apiRequest(this, 'GET', '/templates');
				const rows = (res?.data ?? []) as IDataObject[];
				return rows.map((t) => ({
					name: String(t.name ?? t.title ?? `Template ${t.id}`),
					value: Number(t.id),
				}));
			},
			async getFlows(this: ILoadOptionsFunctions): Promise<INodePropertyOptions[]> {
				const res = await apiRequest(this, 'GET', '/flows');
				const rows = (res?.data ?? []) as IDataObject[];
				return rows.map((f) => ({
					name: String(f.name ?? `Flow ${f.id}`),
					value: Number(f.id),
				}));
			},
		},
	};

	async execute(this: IExecuteFunctions): Promise<INodeExecutionData[][]> {
		const items = this.getInputData();
		const out: INodeExecutionData[] = [];

		for (let i = 0; i < items.length; i++) {
			try {
				const resource = this.getNodeParameter('resource', i) as string;
				const operation = this.getNodeParameter('operation', i) as string;
				let responseData: IDataObject;

				if (resource === 'message' && operation === 'send') {
					const type = this.getNodeParameter('type', i) as string;
					const body: IDataObject = {
						to: this.getNodeParameter('to', i) as string,
						type,
					};
					const deviceId = this.getNodeParameter('deviceId', i, '') as string;
					if (deviceId) body.device_id = deviceId;
					if (['text', 'image', 'video', 'document'].includes(type)) {
						const text = this.getNodeParameter('text', i, '') as string;
						if (text) body.text = text;
					}
					if (['image', 'video', 'document', 'audio'].includes(type)) {
						body.media_url = this.getNodeParameter('mediaUrl', i) as string;
					}
					if (type === 'location') {
						body.latitude = this.getNodeParameter('latitude', i) as number;
						body.longitude = this.getNodeParameter('longitude', i) as number;
					}
					responseData = await apiRequest(this, 'POST', '/messages', body);
				} else if (resource === 'contact' && operation === 'create') {
					const extra = this.getNodeParameter('contactExtra', i, {}) as IDataObject;
					const body: IDataObject = {
						name: this.getNodeParameter('name', i) as string,
						phone: this.getNodeParameter('phone', i) as string,
						...extra,
					};
					responseData = await apiRequest(this, 'POST', '/contacts', body);
				} else if (resource === 'template' && operation === 'send') {
					const id = this.getNodeParameter('templateId', i) as number;
					const body: IDataObject = { to: this.getNodeParameter('to', i) as string };
					const vars = (this.getNodeParameter('bodyVars', i, '') as string).trim();
					if (vars) {
						body.body = vars.split(',').map((v) => v.trim());
					}
					responseData = await apiRequest(this, 'POST', `/templates/${id}/send`, body);
				} else if (resource === 'flow' && operation === 'enroll') {
					const id = this.getNodeParameter('flowId', i) as number;
					const by = this.getNodeParameter('contactBy', i) as string;
					const value = this.getNodeParameter('contactValue', i) as string;
					const body: IDataObject = by === 'contact_id' ? { contact_id: Number(value) } : { phone: value };
					responseData = await apiRequest(this, 'POST', `/flows/${id}/enroll`, body);
				} else {
					throw new NodeOperationError(this.getNode(), `Unsupported operation ${resource}.${operation}`);
				}

				out.push({ json: responseData ?? {}, pairedItem: { item: i } });
			} catch (error) {
				if (this.continueOnFail()) {
					out.push({ json: { error: (error as Error).message }, pairedItem: { item: i } });
					continue;
				}
				throw error;
			}
		}

		return [out];
	}
}
