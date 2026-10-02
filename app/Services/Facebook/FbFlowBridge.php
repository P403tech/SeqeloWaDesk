<?php

namespace App\Services\Facebook;

use App\Models\FacebookPage;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Laravel → Node handoff for Facebook Messenger flows.
 *
 * WaDesk's WhatsApp flow runtime (node/services/flowService.js) resolves a phone
 * sender and can only talk to Baileys/WABA/Twilio — it has no Messenger branch.
 * So Facebook flows run on the SEPARATE, ported Node runtime
 * (node/services/facebookFlowService.js, mounted at /api/facebook-flow/inbound).
 *
 * This bridge POSTs an inbound FB event to that engine. Node answers
 * SYNCHRONOUSLY whether a flow consumed the message (`consumed`), which lets the
 * caller skip WaDesk's PHP keyword auto-reply / AI so the customer never gets a
 * double reply — exactly how the WhatsApp Baileys/WABA paths already behave.
 */
class FbFlowBridge
{
    /**
     * Hand an inbound FB message to the Node flow engine.
     *
     * @param  array|null  $flow  flow_data — REQUIRED to START a flow, omitted to RESUME a parked session.
     * @return bool  true when a flow consumed the message (caller must not auto-reply).
     */
    public static function handoff(
        FacebookPage $page,
        string $psid,
        string $text,
        ?array $flow = null,
        $flowId = null,
        array $vars = [],
        string $commentId = ''
    ): bool {
        $nodeUrl = (string) (SystemSetting::get('baileys_server_url', '') ?: env('SERVER_URL', ''));
        if ($nodeUrl === '' || $psid === '') {
            // Was a SILENT return — the #1 "flow matched but nothing happened"
            // cause: no Node URL configured (baileys_server_url / SERVER_URL), so
            // the flow engine is never even called. Log it so it's not invisible.
            Log::warning('[FB-FLOW-BRIDGE] skipped — cannot reach Node', [
                'has_node_url' => $nodeUrl !== '',
                'psid_present' => $psid !== '',
                'page_id'      => $page->id,
                'flow_id'      => $flowId,
            ]);
            return false;
        }

        // auth = {base, pageId, token} — Graph base so Node's graphSend hits the
        // same /{pageId}/messages endpoint. Host is always graph.facebook.com.
        $v = \App\Services\Facebook\FacebookPageClient::version();
        $endpoint = rtrim($nodeUrl, '/') . '/api/facebook-flow/inbound';

        // Facebook apps with "Require App Secret" (Dashboard → Settings → Advanced)
        // enforce appsecret_proof on EVERY server Graph call. FacebookPageClient
        // adds it for PHP-side sends; the Node flow engine's graphSend needs it too
        // or its sends fail with #100 "API calls from the server require an
        // appsecret_proof argument" (flow starts, but the customer gets nothing).
        // Compute it here (same token + secret as FacebookPageClient) and pass it
        // in auth so Node can attach it.
        // Use the SAME secret FacebookPageClient uses — the page's own workspace
        // Meta app when it brought its own keys (else the admin app). Otherwise a
        // client-app page token would be proofed with the admin secret and Graph
        // rejects the flow's sends (#100), so automation silently delivers nothing.
        if ($page->workspace_id) {
            \App\Services\Facebook\FacebookPageClient::bindWorkspace((int) $page->workspace_id);
        }
        $appSecret = \App\Services\Facebook\FacebookPageClient::appSecret();
        $proof = ($appSecret !== '' && $page->access_token)
            ? hash_hmac('sha256', (string) $page->access_token, $appSecret)
            : '';

        Log::info('[FB-FLOW-BRIDGE] handoff →', [
            'endpoint' => $endpoint,
            'page_id'  => $page->page_id,
            'psid'     => $psid,
            'has_flow' => $flow !== null,   // true = START a keyword flow, false = RESUME a parked session
            'flow_id'  => $flowId,
            'text'     => mb_substr($text, 0, 40),
        ]);

        try {
            $r = Http::withHeaders(['X-Node-Token' => node_token()])
                ->timeout(15)
                ->acceptJson()
                ->post($endpoint, array_filter([
                    'pageId'      => $page->page_id,
                    'workspaceId' => $page->workspace_id,
                    'psid'        => $psid,
                    'text'        => $text,
                    'commentId'   => $commentId,
                    'auth'        => array_filter([
                        'base'            => "https://graph.facebook.com/{$v}",
                        'pageId'          => $page->page_id,
                        'token'           => $page->access_token,
                        'appsecret_proof' => $proof,   // empty when no app secret set → Node omits it
                    ], fn ($x) => $x !== null && $x !== ''),
                    'flow'      => $flow,
                    'flowId'    => $flowId,
                    'vars'      => (object) $vars,
                    'appDomain' => rtrim((string) (config('app.url') ?: url('/')), '/'),
                ], fn ($v) => $v !== null));

            if (!$r->successful()) {
                // 404 here = node/services/facebookFlowService.js not deployed /
                // not mounted at /api/facebook-flow/inbound → deploy the Node side.
                Log::warning('[FB-FLOW-BRIDGE] Node ' . $r->status() . ': ' . mb_substr((string) $r->body(), 0, 200));
                return false;
            }

            $consumed = (bool) $r->json('consumed', false);
            // consumed=false means Node received it but NO flow ran/consumed it
            // (e.g. keyword didn't match on the Node side, or no parked session).
            Log::info('[FB-FLOW-BRIDGE] result', [
                'status'   => $r->status(),
                'consumed' => $consumed,
                'flow_id'  => $flowId,
                'body'     => mb_substr((string) $r->body(), 0, 200),
            ]);
            return $consumed;
        } catch (\Throwable $e) {
            Log::warning('[FB-FLOW-BRIDGE] Node unreachable: ' . mb_substr($e->getMessage(), 0, 150));
            return false;
        }
    }
}
