import {
	IDataObject,
	IHookFunctions,
	IHttpRequestMethods,
	IHttpRequestOptions,
	INodeType,
	INodeTypeDescription,
	IWebhookFunctions,
	IWebhookResponseData,
	NodeConnectionType,
} from 'n8n-workflow';

/** One authenticated request against the workspace REST API (hook context). */
async function apiRequest(
	ctx: IHookFunctions,
	method: IHttpRequestMethods,
	path: string,
	body?: IDataObject,
): Promise<any> {
	const creds = await ctx.getCredentials('waDeskApi');
	const base = String(creds.baseUrl || '').replace(/\/+$/, '');
	const options: IHttpRequestOptions = {
		method,
		url: base + path,
		json: true,
		headers: { Accept: 'application/json' },
	};
	if (body && Object.keys(body).length) options.body = body;
	return ctx.helpers.httpRequestWithAuthentication.call(ctx, 'waDeskApi', options);
}

/**
 * WaDesk Trigger — starts a workflow when a subscribed event fires in the
 * workspace. On activation it registers this node's n8n webhook URL against the
 * workspace REST API (POST /webhooks) for the chosen events; on deactivation it
 * removes that registration (DELETE /webhooks/{id}). No polling.
 */
export class WaDeskTrigger implements INodeType {
	description: INodeTypeDescription = {
		displayName: 'WaDesk Trigger',
		name: 'waDeskTrigger',
		icon: 'file:wadesk.svg',
		group: ['trigger'],
		version: 1,
		subtitle: '={{$parameter["events"].join(", ")}}',
		description: 'Starts the workflow on WaDesk events (messages, contacts, campaigns...)',
		defaults: { name: 'WaDesk Trigger' },
		inputs: [],
		outputs: ['main'] as NodeConnectionType[],
		credentials: [{ name: 'waDeskApi', required: true }],
		webhooks: [
			{
				name: 'default',
				httpMethod: 'POST',
				responseMode: 'onReceived',
				path: 'webhook',
			},
		],
		properties: [
			{
				displayName: 'Events',
				name: 'events',
				type: 'multiOptions',
				required: true,
				default: ['message_received'],
				description: 'Which workspace events start this workflow',
				options: [
					{ name: 'Message Received', value: 'message_received' },
					{ name: 'Message Sent', value: 'message_sent' },
					{ name: 'Message Delivered', value: 'message_delivered' },
					{ name: 'Message Read', value: 'message_read' },
					{ name: 'Message Failed', value: 'message_failed' },
					{ name: 'Contact Created', value: 'contact_created' },
					{ name: 'Contact Updated', value: 'contact_updated' },
					{ name: 'Contact Opted In', value: 'contact_opt_in' },
					{ name: 'Campaign Created', value: 'campaign_created' },
					{ name: 'Campaign Status Updated', value: 'campaign_status_updated' },
					{ name: 'Campaign Contact Status Updated', value: 'campaign_contact_status_updated' },
					{ name: 'Campaign Contact Clicked', value: 'campaign_contact_clicked' },
					{ name: 'Campaign Contact Replied', value: 'campaign_contact_replied' },
					{ name: 'Broadcast Created', value: 'broadcast_created' },
					{ name: 'Broadcast Status Updated', value: 'broadcast_status_updated' },
					{ name: 'Broadcast Message Status Updated', value: 'broadcast_message_status_updated' },
					{ name: 'Device Status Updated', value: 'device_status_updated' },
				],
			},
		],
	};

	webhookMethods = {
		default: {
			async checkExists(this: IHookFunctions): Promise<boolean> {
				const data = this.getWorkflowStaticData('node');
				return Boolean(data.webhookId);
			},

			async create(this: IHookFunctions): Promise<boolean> {
				const webhookUrl = this.getNodeWebhookUrl('default');
				const events = this.getNodeParameter('events') as string[];
				const data = this.getWorkflowStaticData('node');

				const res = await apiRequest(this, 'POST', '/webhooks', {
					name: 'n8n',
					url: webhookUrl,
					events,
					active: true,
				});

				const id = res?.data?.id;
				if (!id) return false;
				data.webhookId = id;
				return true;
			},

			async delete(this: IHookFunctions): Promise<boolean> {
				const data = this.getWorkflowStaticData('node');
				if (!data.webhookId) return true;
				try {
					await apiRequest(this, 'DELETE', `/webhooks/${data.webhookId}`);
				} catch {
					// Endpoint may already be gone — treat as removed.
				}
				delete data.webhookId;
				return true;
			},
		},
	};

	async webhook(this: IWebhookFunctions): Promise<IWebhookResponseData> {
		const body = this.getBodyData() as IDataObject;
		return {
			workflowData: [this.helpers.returnJsonArray([body])],
		};
	}
}
