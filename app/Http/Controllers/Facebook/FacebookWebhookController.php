<?php

namespace App\Http\Controllers\Facebook;

use App\Http\Controllers\Controller;
use App\Models\FacebookPage;
use App\Models\SystemSetting;
use App\Services\Facebook\FacebookIngestService;
use App\Services\Facebook\FacebookWebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Meta Facebook Page webhook endpoint.
 *   GET  /webhooks/facebook → subscription verification (hub.challenge)
 *   POST /webhooks/facebook → signed events (feed/comments/mentions/messages)
 *
 * P0 verifies the handshake + validates the X-Hub-Signature-256 HMAC and acks
 * fast (so the buyer can complete Meta's "Verify and Save"). Turning events
 * into inbox conversations lands in P1.
 */
class FacebookWebhookController extends Controller
{
    /** GET verify handshake — echo hub.challenge as plain text when the token matches. */
    public function verify(Request $request)
    {
        $mode = (string) $request->query('hub_mode', $request->query('hub.mode', ''));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));

        // Reuse the shared Meta webhook verify token (auto-generated if the admin
        // never set one), so a bring-your-own-app client can complete the
        // handshake without any admin webhook config. A Facebook-specific
        // override still wins when present.
        $expected = (string) (SystemSetting::get('fb_webhook_verify_token', '') ?: \App\Support\MetaWebhook::verifyToken());
        if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token)) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('forbidden', 403);
    }

    /** POST events — verify the signature, then ack. Ingest to inbox comes in P1. */
    public function handle(Request $request)
    {
        $raw = $request->getContent();
        $sig = (string) $request->header('X-Hub-Signature-256', '');

        $matched = FacebookWebhookSignature::verify($raw, $sig);
        if ($matched === null) {
            // A configured secret that does not match is a forged payload.
            // With no secret at all, acknowledge so Meta keeps the subscription,
            // but do not store or act on the unsigned body.
            if (FacebookWebhookSignature::configuredSecrets() !== []) {
                Log::warning('[FB-HOOK] signature verification failed');

                return response('invalid signature', 403);
            }
            Log::warning('[FB-HOOK] no app secret configured; event acknowledged and not stored');

            return response('ok', 200);
        }

        $payload = $request->json()->all();
        if (($payload['object'] ?? '') !== 'page') {
            return response('ok', 200);
        }

        // Delivery is at-least-once, unordered and batched — iterate every
        // entry and each event; the ingest writer dedups on the Meta id.
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            $pageId = (string) ($entry['id'] ?? '');
            if ($pageId === '') {
                continue;
            }
            // The SAME Page can be connected in more than one workspace (unique
            // key is workspace_id+page_id). Meta delivers one webhook per Page,
            // so fan it out to EVERY workspace that connected it — else all but
            // one silently receive nothing. The ingest writer dedups per
            // workspace, so no duplication.
            $pages = FacebookPage::where('page_id', $pageId)->where('status', 'connected')->get();
            if ($pages->isEmpty()) {
                continue; // not a Page connected to any workspace here
            }

            foreach ($pages as $page) {
                // Messenger DMs arrive under entry[].messaging[].
                foreach ((array) ($entry['messaging'] ?? []) as $ev) {
                    try {
                        FacebookIngestService::messengerEvent($page, (array) $ev);
                    } catch (\Throwable $e) {
                        Log::warning('[FB-HOOK] messaging ingest failed: '.$e->getMessage(), ['page' => $page->id]);
                    }
                }

                // Change events under entry[].changes[]. This used to `continue`
                // on anything that wasn't 'feed', which silently discarded
                // leadgen — so Instant Form submissions never reached us even
                // once the Page was subscribed to the field.
                foreach ((array) ($entry['changes'] ?? []) as $change) {
                    $field = (string) ($change['field'] ?? '');
                    $value = (array) ($change['value'] ?? []);

                    if ($field === 'feed') {
                        try {
                            FacebookIngestService::feedComment($page, $value);
                        } catch (\Throwable $e) {
                            Log::warning('[FB-HOOK] feed ingest failed: '.$e->getMessage(), ['page' => $page->id]);
                        }
                        continue;
                    }

                    if ($field === 'leadgen') {
                        // Meta's leadgen webhook carries IDs ONLY — never the
                        // answers. The ingest service does the second Graph call
                        // (GET /{leadgen_id}) that fetches them, which is why it
                        // needs leads_retrieval and not just a webhook.
                        try {
                            app(\App\Services\Facebook\MetaLeadIngestService::class)->ingest(
                                $page,
                                (string) ($value['leadgen_id'] ?? ''),
                                (string) ($value['form_id'] ?? ''),
                            );
                        } catch (\Throwable $e) {
                            Log::warning('[FB-HOOK] leadgen ingest failed: '.$e->getMessage(), [
                                'page' => $page->id, 'leadgen_id' => $value['leadgen_id'] ?? null,
                            ]);
                        }
                        continue;
                    }
                }
            }
        }

        return response('ok', 200);
    }
}
