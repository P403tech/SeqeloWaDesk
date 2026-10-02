<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use App\Models\SystemSetting;
use App\Models\Webhook;
use App\Services\PlanLimitGuard;
use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * /n8n — the n8n connector.
 *
 * We do NOT bundle or host n8n (that would need n8n's paid Embed licence);
 * we CONNECT to the user's own n8n instance over the two rails this app
 * already has:
 *
 *   Triggers (this app -> n8n): the user's n8n Webhook node subscribes to
 *   events. This page manages ONE outbound Webhook row (marked
 *   environment = 'n8n') pointed at that node's URL, reusing the existing
 *   WebhookService delivery pipeline — HMAC signing, retries, delivery log.
 *   No new delivery engine is introduced.
 *
 *   Actions (n8n -> this app): n8n calls the REST API at /api/v1 with the
 *   workspace's key. Key minting stays on /developers; this page only shows
 *   the base URL + whether a key exists, and links there to create one.
 *
 * Gated on the same plan feature as outbound webhooks (access_outbound_webhooks)
 * because that is exactly the infrastructure it rides on.
 */
class N8nController extends Controller
{
    /** Marker on the single n8n-managed Webhook row (in the `environment` column). */
    private const MARKER = 'n8n';

    /** Events pre-ticked for a brand-new connection. */
    private const DEFAULT_EVENTS = ['message_received', 'message_sent', 'contact_created'];

    /** The single Webhook row that represents this workspace's n8n link, if any. */
    private function connection(): ?Webhook
    {
        return Webhook::query()->forCurrentWorkspace()
            ->where('environment', self::MARKER)
            ->first();
    }

    public function index()
    {
        $conn   = $this->connection();
        $wsId   = (int) (Auth::user()?->current_workspace_id ?? 0);
        $apiKey = ApiKey::query()->where('workspace_id', $wsId)->whereNull('revoked_at')->latest('id')->first();

        return view('user.n8n.index', [
            'connection'   => $conn,
            'events'       => WebhookService::availableEvents(),
            'selected'     => $conn?->events ?: self::DEFAULT_EVENTS,
            'baseUrl'      => rtrim((string) config('app.url'), '/').'/api/v1',
            'hasApiKey'    => $apiKey && $apiKey->isActive(),
            'apiKeyPrefix' => $apiKey?->prefix,
            'editorUrl'    => trim((string) SystemSetting::get("n8n_editor_url_ws{$wsId}", '')),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        // Same feature the outbound-webhook engine (which this rides on) requires.
        PlanLimitGuard::feature($request->user()?->currentWorkspace, 'access_outbound_webhooks');

        $data = $request->validate([
            'n8n_url'  => 'required|url|max:2048',
            'events'   => 'required|array|min:1',
            'events.*' => 'string|max:64',
        ]);

        // Keep only events from the known catalogue.
        $events = array_values(array_intersect($data['events'], array_keys(WebhookService::availableEvents())));
        if (! $events) {
            return back()->withErrors(['events' => __('Choose at least one event to send to n8n.')]);
        }

        $conn = $this->connection();
        // Every payload is HMAC-signed; keep the existing secret or mint one so
        // the n8n side can verify the request really came from here.
        $secret = $conn?->secret ?: Str::random(48);

        Webhook::updateOrCreate(
            ['workspace_id' => (int) $request->user()->current_workspace_id, 'environment' => self::MARKER],
            [
                'user_id'     => Auth::id(),
                'name'        => 'n8n',
                'http_method' => 'POST',
                'webhook_url' => $data['n8n_url'],
                'events'      => $events,
                'secret'      => $secret,
                'status'      => true,
            ],
        );

        return redirect()->route('user.n8n')
            ->with('status', __('n8n connection saved. Subscribed events will now be sent to your n8n workflow.'));
    }

    /**
     * Save (or clear) the URL of the user's OWN n8n editor, shown in the embed
     * panel. Stored as a per-workspace convenience setting (namespaced key in
     * the global settings store); an empty value clears it. We only ever embed
     * the buyer's own single n8n — never a hosted per-tenant editor (that would
     * need n8n's paid Embed licence).
     */
    public function saveEmbed(Request $request): RedirectResponse
    {
        PlanLimitGuard::feature($request->user()?->currentWorkspace, 'access_outbound_webhooks');
        $data = $request->validate(['editor_url' => 'nullable|url|max:2048']);
        $wsId = (int) $request->user()->current_workspace_id;
        SystemSetting::set("n8n_editor_url_ws{$wsId}", trim((string) ($data['editor_url'] ?? '')), 'string');

        return redirect()->route('user.n8n')->with('status', __('n8n editor link updated.'));
    }

    /** Fire one test event to the saved n8n URL (reuses the webhook test path). */
    public function test(): JsonResponse
    {
        $conn = $this->connection();
        if (! $conn) {
            return response()->json(['ok' => false, 'message' => __('Save your n8n URL first.')], 422);
        }
        $delivery = WebhookService::testFire($conn);

        return response()->json([
            'ok'         => true,
            'statusCode' => $delivery->status_code,
            'latencyMs'  => $delivery->latency_ms,
            'isOk'       => $delivery->is_success,
        ]);
    }

    public function disconnect(): RedirectResponse
    {
        $this->connection()?->delete();

        return redirect()->route('user.n8n')->with('status', __('n8n disconnected.'));
    }
}
