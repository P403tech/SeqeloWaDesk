# n8n-nodes-wadesk

An [n8n](https://n8n.io) community node package that connects **WaDesk** (WhatsApp &
omni-channel messaging) to your n8n workflows.

It ships two nodes:

- **WaDesk Trigger** — starts a workflow when a workspace event fires
  (message received/sent/delivered/read/failed, contact created/updated/opted-in,
  campaign and broadcast events, device status). Registers itself against the
  workspace REST API on activation and removes itself on deactivation — no polling.
- **WaDesk** — actions your workflow can run:
  - **Message → Send** (text, image, video, document, audio, location)
  - **Contact → Create**
  - **Template → Send** (with body variables)
  - **Flow → Enroll a contact** (by phone or contact id)

> WaDesk is a separate product with its own licence. This package only *connects to*
> your WaDesk instance over its public REST API — it neither bundles nor hosts WaDesk.

## Install

Community nodes (n8n **Settings → Community nodes → Install**):

```
n8n-nodes-wadesk
```

Or self-host from a build:

```bash
npm install
npm run build
# then point N8N_CUSTOM_EXTENSIONS at this folder, or `npm link` it into ~/.n8n/custom
```

## Credentials — “WaDesk API”

| Field | Where to find it |
| --- | --- |
| **Base URL** | The **Automation → n8n** page in the app (ends in `/api/v1`). |
| **API Key** | The **Developers / API keys** page — create one key per workspace (`wsk_…`). |

The credential’s **Test** button calls `GET /me`.

## Trigger setup

1. Add a **WaDesk Trigger** node, pick the events, and **activate** the workflow.
2. Activation calls `POST /api/v1/webhooks` with this node’s webhook URL and your
   chosen events. Deactivating (or deleting) the node removes that registration.

## Actions — endpoints used

| Node operation | REST call |
| --- | --- |
| Message → Send | `POST /api/v1/messages` |
| Contact → Create | `POST /api/v1/contacts` |
| Template → Send | `POST /api/v1/templates/{id}/send` |
| Flow → Enroll | `POST /api/v1/flows/{id}/enroll` |

Template and Flow pickers load live from `GET /api/v1/templates` and `GET /api/v1/flows`.

## Compatibility

Built against the n8n community-node API version 1 (`n8n-workflow`). Requires Node 18.10+.

## License

MIT
