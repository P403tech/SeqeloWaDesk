<?php

namespace App\Http\Controllers;

use App\Models\IncomingWebhook;
use App\Models\IncomingWebhookEvent;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Incoming (inbound) webhooks. The workspace generates a URL here, hands
 * it to any external service, and we:
 *   1. capture every request that hits /hooks/in/{token} into
 *      incoming_webhook_events (so the operator can inspect the payload),
 *   2. optionally relay it on to a forward_url ("send to their location").
 *
 * The public `receive()` action is session-less + CSRF-exempt (the random
 * token is the access control). Everything else is workspace-scoped under
 * the authenticated /webhooks group.
 */
class IncomingWebhookController extends Controller
{
    /** Keep at most this many captured events per hook (newest wins). */
    private const MAX_EVENTS = 100;

    // -----------------------------------------------------------------
    // Public receiver — external services POST here.
    // -----------------------------------------------------------------
    public function receive(Request $request, string $token): JsonResponse
    {
        $hook = IncomingWebhook::query()->where('token', $token)->first();
        if (!$hook || !$hook->is_active) {
            return response()->json(['ok' => false, 'error' => 'unknown or inactive webhook'], 404);
        }

        // Capture a safe subset of headers (drop cookies / auth so we never
        // persist the caller's credentials in plaintext).
        $headers = [];
        foreach ($request->headers->all() as $k => $v) {
            $kl = strtolower($k);
            if (in_array($kl, ['cookie', 'authorization', 'x-csrf-token', 'x-xsrf-token'], true)) continue;
            $headers[$k] = is_array($v) ? implode(', ', $v) : (string) $v;
        }
        $raw = (string) $request->getContent();
        // Cap stored body at 64 KB so a giant POST can't bloat the table.
        $payload = mb_strlen($raw) > 65536 ? mb_substr($raw, 0, 65536) . "\n…(truncated)" : $raw;

        $event = IncomingWebhookEvent::create([
            'incoming_webhook_id' => $hook->id,
            'method'              => substr($request->method(), 0, 8),
            'source_ip'           => $request->ip(),
            'content_type'        => substr((string) $request->header('Content-Type', ''), 0, 191) ?: null,
            'headers'             => $headers,
            'payload'             => $payload,
            'received_at'         => now(),
        ]);

        $hook->forceFill([
            'received_count'   => $hook->received_count + 1,
            'last_received_at' => now(),
        ])->save();

        // Lead capture — turn this payload into a Contact (tagged as a lead) and
        // optionally enroll it in a flow. Best-effort: never block the 200.
        if ($hook->leadCaptureEnabled()) {
            try {
                $this->captureLead($hook, $request, $event);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[INCOMING-WH] lead capture failed: ' . $e->getMessage());
            }
        }

        // Relay onward to the operator's destination, best-effort. Failures
        // are recorded on the event, never block the 200 to the caller.
        if ($hook->forward_enabled && !empty($hook->forward_url)) {
            // SSRF guard: forward_url is operator-supplied. Re-validate at relay
            // time (not just save time — DNS can change) and refuse private/
            // loopback/link-local/reserved targets so this public token endpoint
            // can't be used to probe or POST into internal services (IMDS, redis,
            // localhost admin, …). Redirects are disabled so a public host can't
            // 302 us onto an internal one.
            $ssrfErr = $this->guardSsrf((string) $hook->forward_url);
            if ($ssrfErr) {
                $event->forceFill([
                    'forwarded'     => true,
                    'forward_error' => mb_substr('forward blocked: ' . $ssrfErr, 0, 500),
                ])->save();
            } else {
                try {
                    $resp = Http::timeout(10)
                        ->withOptions(['allow_redirects' => false])
                        ->withHeaders(['Content-Type' => $request->header('Content-Type', 'application/json')])
                        ->withBody($raw, $request->header('Content-Type', 'application/json'))
                        ->post($hook->forward_url);
                    $event->forceFill([
                        'forwarded'      => true,
                        'forward_status' => $resp->status(),
                    ])->save();
                } catch (\Throwable $e) {
                    $event->forceFill([
                        'forwarded'     => true,
                        'forward_error' => mb_substr($e->getMessage(), 0, 500),
                    ])->save();
                }
            }
        }

        // Prune old events beyond the cap.
        $keepIds = IncomingWebhookEvent::where('incoming_webhook_id', $hook->id)
            ->orderByDesc('id')->limit(self::MAX_EVENTS)->pluck('id');
        IncomingWebhookEvent::where('incoming_webhook_id', $hook->id)
            ->whereNotIn('id', $keepIds)->delete();

        return response()->json(['ok' => true, 'received' => true, 'event_id' => $event->id]);
    }

    // -----------------------------------------------------------------
    // Authenticated UI (workspace-scoped, /webhooks/incoming).
    // -----------------------------------------------------------------
    public function index(Request $request): View
    {
        $hooks = IncomingWebhook::query()
            ->forCurrentWorkspace()
            ->with(['events' => fn ($q) => $q->limit(20)])
            ->orderByDesc('id')
            ->get();

        // Flows the operator can auto-enroll a captured lead into (chat flows on
        // the default WhatsApp channel — same set the keyword rules use).
        $flows = \App\Models\Flow::query()->forCurrentWorkspace()
            ->orderByDesc('id')->get(['id', 'flow_name'])
            ->map(fn ($f) => ['id' => $f->id, 'name' => $f->flow_name ?: ('Flow #' . $f->id)])
            ->values();

        return view('user.webhooks.incoming', ['hooks' => $hooks, 'flows' => $flows]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:128',
        ]);

        IncomingWebhook::create([
            'workspace_id' => Auth::user()->current_workspace_id,
            'user_id'      => Auth::id(),
            'name'         => $data['name'] ?? 'Incoming webhook',
            'token'        => Str::random(40),
            'is_active'    => true,
        ]);

        return redirect()->route('user.webhooks.incoming')
            ->with('status', 'Incoming webhook generated — copy its URL below.');
    }

    public function forward(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'forward_url'     => 'nullable|url|max:1024',
            'forward_enabled' => 'nullable|boolean',
        ]);
        $hook = $this->resolve($id);
        $enabled = (bool) ($data['forward_enabled'] ?? false);
        if ($enabled && empty($data['forward_url'])) {
            return back()->withErrors(['forward_url' => 'Enter a destination URL to forward to.']);
        }
        // SSRF guard at save time — reject private/loopback/link-local/reserved
        // destinations up front (relay time re-checks too, in case DNS changes).
        if (!empty($data['forward_url'])) {
            $ssrfErr = $this->guardSsrf($data['forward_url']);
            if ($ssrfErr) {
                return back()->withErrors(['forward_url' => 'That destination is not allowed: ' . $ssrfErr]);
            }
        }
        $hook->update([
            'forward_url'     => $data['forward_url'] ?: null,
            'forward_enabled' => $enabled,
        ]);
        return back()->with('status', 'Forwarding settings saved.');
    }

    /**
     * Save the lead-capture mapping for a hook — which payload fields map to
     * phone / name / email, the tag to apply, and an optional flow to enroll.
     */
    public function leadCapture(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'lead_enabled' => 'nullable|boolean',
            'lead_phone'   => 'nullable|string|max:120',
            'lead_name'    => 'nullable|string|max:120',
            'lead_email'   => 'nullable|string|max:120',
            'lead_cc'      => 'nullable|string|max:8',
            'lead_tag'     => 'nullable|string|max:60',
            'lead_flow_id' => 'nullable|integer',
        ]);
        $hook = $this->resolve($id);

        $flowId = (int) ($data['lead_flow_id'] ?? 0);
        // Only accept a flow that belongs to this hook's workspace.
        if ($flowId > 0 && ! \App\Models\Flow::query()
                ->where('workspace_id', $hook->workspace_id)->whereKey($flowId)->exists()) {
            $flowId = 0;
        }

        $hook->update(['lead_config' => [
            'enabled'      => (bool) ($data['lead_enabled'] ?? false),
            'phone'        => trim((string) ($data['lead_phone'] ?? '')) ?: null,
            'name'         => trim((string) ($data['lead_name'] ?? '')) ?: null,
            'email'        => trim((string) ($data['lead_email'] ?? '')) ?: null,
            'country_code' => preg_replace('/\D+/', '', (string) ($data['lead_cc'] ?? '')) ?: null,
            'tag'          => trim((string) ($data['lead_tag'] ?? 'Lead')) ?: 'Lead',
            'flow_id'      => $flowId ?: null,
        ]]);

        return back()->with('status', 'Lead capture settings saved.');
    }

    /**
     * Turn a received payload into a Contact. Extracts phone/name/email using
     * the operator's field mapping (with sensible fallbacks), dedupes by phone
     * hash, tags it, and optionally enrolls it in a flow. Runs in the PUBLIC,
     * session-less receive() path — so it scopes everything by $hook->workspace_id
     * (never auth()/forCurrentWorkspace()).
     */
    private function captureLead(IncomingWebhook $hook, Request $request, IncomingWebhookEvent $event): void
    {
        $cfg = is_array($hook->lead_config) ? $hook->lead_config : [];

        // Body may be JSON or form-encoded — normalise to one array.
        $input = $request->all();
        if (empty($input)) {
            $decoded = json_decode((string) $request->getContent(), true);
            if (is_array($decoded)) $input = $decoded;
        }
        if (empty($input)) return;

        $phone = $this->firstValue($input, $cfg['phone'] ?? null, ['phone', 'mobile', 'phone_number', 'whatsapp', 'wa_number', 'number', 'contact_number', 'msisdn']);
        $name  = $this->firstValue($input, $cfg['name'] ?? null, ['name', 'full_name', 'fullname', 'contact_name', 'first_name']);
        $email = $this->firstValue($input, $cfg['email'] ?? null, ['email', 'email_address', 'mail']);

        // Stitch first + last name when only a first name was found.
        $last = data_get($input, 'last_name');
        if ($name && is_scalar($last) && trim((string) $last) !== '' && ! str_contains($name, (string) $last)) {
            $name = trim($name . ' ' . $last);
        }

        $digits = preg_replace('/\D+/', '', (string) $phone);
        // Apply a default country code for local-looking numbers.
        $cc = preg_replace('/\D+/', '', (string) ($cfg['country_code'] ?? ''));
        if ($cc !== '' && strlen($digits) > 0 && strlen($digits) <= 10 && ! str_starts_with($digits, $cc)) {
            $digits = $cc . $digits;
        }
        if (strlen($digits) < 8 || strlen($digits) > 15) return; // no usable phone → no lead

        $contact = \App\Models\Contact::rememberPhone($hook->workspace_id, $hook->user_id, $digits, $name ?: null);
        if (! $contact) return;

        if ($name && trim($name) !== '') $contact->name = trim($name);
        if ($email) $contact->email = $email;
        $contact->save();

        // Tag as a lead (contact_tag pivot → findable in campaigns/segments).
        $tagName = trim((string) ($cfg['tag'] ?? 'Lead')) ?: 'Lead';
        $tag = \App\Models\Tag::firstOrCreate(
            ['workspace_id' => $hook->workspace_id, 'name' => $tagName],
            ['slug' => \Illuminate\Support\Str::slug($tagName) ?: ('lead-' . \Illuminate\Support\Str::random(4)), 'color' => '#2E7D32']
        );
        $contact->tags()->syncWithoutDetaching([$tag->id]);

        // Optionally enroll the fresh lead into a flow (welcome / qualify).
        $flowId = (int) ($cfg['flow_id'] ?? 0);
        if ($flowId > 0) {
            $flow = \App\Models\Flow::query()->where('workspace_id', $hook->workspace_id)->find($flowId);
            if ($flow) {
                try {
                    app(\App\Services\Flow\FlowEnrollmentService::class)->enroll($contact, $flow);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[INCOMING-WH] flow enroll failed: ' . $e->getMessage());
                }
            }
        }

        $event->forceFill(['lead_contact_id' => $contact->id])->save();

        // Alert the team (in-app + browser push) that a new lead landed.
        try {
            app(\App\Services\Inbox\NotificationDispatcher::class)->notifyNewLead(
                (int) $hook->workspace_id,
                $hook->user_id ? (int) $hook->user_id : null,
                (string) ($contact->name ?: ''),
                $digits,
                (string) ($hook->name ?: 'Webhook')
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[INCOMING-WH] lead notify failed: ' . $e->getMessage());
        }
    }

    /** First non-empty scalar from a configured dot-path, then common fallbacks. */
    private function firstValue(array $input, ?string $configPath, array $fallbacks): ?string
    {
        $paths = array_merge($configPath ? [$configPath] : [], $fallbacks);
        foreach ($paths as $p) {
            $v = data_get($input, $p);
            if (is_scalar($v) && trim((string) $v) !== '') return (string) $v;
        }
        return null;
    }

    public function toggle(int $id): RedirectResponse
    {
        $hook = $this->resolve($id);
        $hook->update(['is_active' => !$hook->is_active]);
        return back()->with('status', $hook->is_active ? 'Webhook activated.' : 'Webhook paused.');
    }

    public function clear(int $id): RedirectResponse
    {
        $hook = $this->resolve($id);
        IncomingWebhookEvent::where('incoming_webhook_id', $hook->id)->delete();
        return back()->with('status', 'Captured events cleared.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $hook = $this->resolve($id);
        IncomingWebhookEvent::where('incoming_webhook_id', $hook->id)->delete();
        $hook->delete();
        return back()->with('status', 'Incoming webhook deleted.');
    }

    /** Recent events for the live inspector (AJAX poll). */
    public function eventsJson(int $id): JsonResponse
    {
        $hook = $this->resolve($id);
        $events = IncomingWebhookEvent::where('incoming_webhook_id', $hook->id)
            ->orderByDesc('id')->limit(20)->get()
            ->map(fn ($e) => [
                'id'         => $e->id,
                'method'     => $e->method,
                'ip'         => $e->source_ip,
                'type'       => $e->content_type,
                'at'         => optional($e->received_at)->diffForHumans(),
                'forwarded'  => $e->forwarded,
                'fwd_status' => $e->forward_status,
                'preview'    => Str::limit((string) $e->payload, 280),
            ]);
        return response()->json([
            'ok'             => true,
            'received_count' => $hook->received_count,
            'events'         => $events,
        ]);
    }

    /** Workspace-scoped fetch (404 otherwise). */
    private function resolve(int $id): IncomingWebhook
    {
        return IncomingWebhook::query()->forCurrentWorkspace()->findOrFail($id);
    }

    /**
     * SSRF guard for the operator-supplied forward_url.
     *
     * Returns NULL when the URL is safe to relay to, or a human-readable
     * error string when it must be refused. Refuses non-http(s) schemes and
     * any hostname that resolves to a private/loopback/link-local/reserved IP
     * (RFC1918, 127.0.0.0/8, 169.254.0.0/16 incl. the cloud metadata IP, ::1,
     * fc00::/7, etc.). Mirrors AiTrainingController::guardSsrf so both
     * outbound-fetch paths fail closed the same way.
     */
    private function guardSsrf(string $url): ?string
    {
        $p = parse_url($url);
        if (!$p || empty($p['scheme']) || empty($p['host'])) {
            return 'invalid URL';
        }
        $scheme = strtolower($p['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return "scheme {$scheme} not allowed (use http or https)";
        }
        $host = strtolower($p['host']);
        if (str_contains($host, 'metadata.') || str_ends_with($host, '.internal')) {
            return 'metadata host not allowed';
        }
        $ips = @gethostbynamel($host) ?: [];
        if (filter_var($host, FILTER_VALIDATE_IP)) $ips = [$host];
        if (empty($ips)) {
            $aaaa = @dns_get_record($host, DNS_AAAA);
            foreach ((array) $aaaa as $rec) {
                if (!empty($rec['ipv6'])) $ips[] = $rec['ipv6'];
            }
        }
        if (empty($ips)) {
            return 'hostname did not resolve to a public IP';
        }
        foreach ($ips as $ip) {
            $public = filter_var($ip, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if ($public === false) {
                return "host resolves to private/reserved IP ({$ip}) — refusing to forward";
            }
        }
        return null;
    }
}
