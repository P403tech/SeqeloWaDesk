<?php

namespace App\Services\Meta;

use App\Models\SystemSetting;
use App\Services\Facebook\FacebookPageClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Self-serve BYO-Meta-app setup: when a workspace enters its OWN Facebook/Instagram
 * App ID + Secret, we auto-configure that app's App-level Webhook so events reach
 * us — the client never touches the Meta dashboard's Webhooks screen or asks an
 * admin/tech to wire it.
 *
 * Official docs (Meta Graph API — Webhooks / App subscriptions):
 *   POST https://graph.facebook.com/{version}/{app-id}/subscriptions
 *        object=page|instagram, callback_url, fields, verify_token,
 *        access_token = {app-id}|{app-secret}   (an APP access token)
 *   → { "success": true }
 *
 * The per-Page / per-IG-account `subscribed_apps` binding is still done at connect
 * time (FacebookPageClient/InstagramService::subscribeWebhooks); THIS class does
 * the one-time APP-level wiring so those bindings actually deliver to us.
 */
class MetaAppWebhookSubscriber
{
    private const GRAPH = 'https://graph.facebook.com';

    /**
     * Subscribe the app's Page + Instagram webhooks. Best-effort, never throws.
     *
     * @return array{page:array{ok:bool,error:?string}, instagram:array{ok:bool,error:?string}}
     */
    public function subscribeAll(string $appId, string $appSecret): array
    {
        $appId     = trim($appId);
        $appSecret = trim($appSecret);
        if ($appId === '' || $appSecret === '') {
            $e = ['ok' => false, 'error' => 'Missing App ID or Secret.'];
            return ['page' => $e, 'instagram' => $e];
        }

        $v        = FacebookPageClient::version();
        $appToken = $appId . '|' . $appSecret;

        // Verify token: reuse the platform's (our /webhooks/* verify endpoints
        // accept it), so Meta's GET handshake succeeds against our callback.
        $fbVerify = (string) (SystemSetting::get('fb_webhook_verify_token', '')
            ?: SystemSetting::get('waba_webhook_verify_token', ''));
        $igVerify = (string) (SystemSetting::get('instagram_webhook_verify_token', '') ?: $fbVerify);

        return [
            'page' => $this->subscribe($v, $appId, $appToken, 'page', url('/webhooks/facebook'), $fbVerify,
                'messages,messaging_postbacks,message_deliveries,message_reads,messaging_referrals,feed'),
            'instagram' => $this->subscribe($v, $appId, $appToken, 'instagram', url('/webhooks/instagram'), $igVerify,
                'messages,messaging_postbacks,comments,live_comments,message_reactions,mentions'),
        ];
    }

    private function subscribe(string $v, string $appId, string $appToken, string $object, string $callback, string $verifyToken, string $fields): array
    {
        if ($verifyToken === '') {
            return ['ok' => false, 'error' => 'No webhook verify token is configured on this platform.'];
        }
        try {
            $r = Http::asForm()->acceptJson()->timeout(20)
                ->post(self::GRAPH . "/{$v}/{$appId}/subscriptions", [
                    'object'       => $object,
                    'callback_url' => $callback,
                    'fields'       => $fields,
                    'verify_token' => $verifyToken,
                    'access_token' => $appToken,
                ]);

            $ok = $r->successful() && in_array($r->json('success'), [true, 'true', 1, '1'], true);
            if (! $ok) {
                Log::warning('[META-APP-WEBHOOK] subscribe failed', [
                    'object' => $object, 'callback' => $callback, 'http' => $r->status(),
                    'error' => $r->json('error'),
                ]);
            } else {
                Log::info('[META-APP-WEBHOOK] subscribed', ['object' => $object, 'callback' => $callback]);
            }

            return ['ok' => $ok, 'error' => $ok ? null : (string) ($r->json('error.message') ?? ('HTTP ' . $r->status()))];
        } catch (\Throwable $e) {
            Log::warning('[META-APP-WEBHOOK] subscribe threw', ['object' => $object, 'error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
