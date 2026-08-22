<?php

namespace App\Http\Controllers\Tiktok;

use App\Http\Controllers\Controller;
use App\Models\TiktokAccount;
use App\Models\TiktokPost;
use App\Services\Tiktok\TiktokClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * TikTok webhook endpoint. TikTok delivers lifecycle events (authorization
 * removed, post publish status) to a single signed callback URL configured in
 * the developer portal — there is no GET verify handshake (Meta-style); the
 * portal validates the URL when you save it.
 *
 * Signature: header `TikTok-Signature: t=<ts>,s=<hex>`. The signed payload is
 * `<ts>.<raw_body>`, HMAC-SHA256 keyed by the app CLIENT SECRET, timing-safe
 * compared. A stale timestamp is rejected (replay guard). The event `content`
 * field is a JSON-ENCODED STRING and must be double-parsed.
 */
class TiktokWebhookController extends Controller
{
    /** Reject events whose signed timestamp is older than this (replay guard). */
    private const MAX_SKEW_SECONDS = 300;

    public function handle(Request $request)
    {
        $raw = $request->getContent();
        $secret = TiktokClient::clientSecret();

        // Verify the signature when a secret is configured. If none is set yet
        // (initial portal setup), ack 200 so saving the callback URL isn't blocked.
        if ($secret !== '') {
            if (! $this->verifySignature($raw, (string) $request->header('TikTok-Signature', ''), $secret)) {
                Log::warning('[TT-HOOK] signature verification failed');

                return response('invalid signature', 403);
            }
        }

        $payload = json_decode($raw, true) ?: [];
        $event   = (string) ($payload['event'] ?? '');
        $openId  = (string) ($payload['user_openid'] ?? '');

        // `content` is a JSON-encoded string — decode it into an array.
        $content = $payload['content'] ?? [];
        if (is_string($content)) {
            $content = json_decode($content, true) ?: [];
        }

        try {
            match ($event) {
                'authorization.removed' => $this->onAuthorizationRemoved($openId),
                'post.publish.complete',
                'post.publish.failed',
                'post.publish.no_longer_available' => $this->onPublishStatus($event, (array) $content),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::warning('[TT-HOOK] handler failed: '.$e->getMessage(), ['event' => $event]);
        }

        return response('ok', 200);
    }

    /**
     * Business Messaging inbound webhook (SEPARATE business-api app). Partner-gated
     * + region-locked. Signed with the BUSINESS app secret. Delivers new_message /
     * new_conversation events → the unified inbox via TiktokIngestService.
     *
     * The exact BM webhook signature header + payload shape are unverified
     * (SPA-blocked in research) — re-confirm against
     * business-api.tiktok.com/portal/docs/business-messaging/v1.3 before prod.
     * Structured defensively: we map a normalised event and never throw.
     */
    public function business(Request $request)
    {
        $raw = $request->getContent();
        $secret = \App\Services\Tiktok\TiktokBusinessClient::appSecret();

        // Business webhooks are signed with the business app secret. Verify when
        // configured; the exact header name is confirmed at integration time.
        if ($secret !== '') {
            $sig = (string) ($request->header('TikTok-Signature', '') ?: $request->header('X-Tt-Signature', ''));
            if ($sig !== '' && ! $this->verifySignature($raw, $sig, $secret)) {
                Log::warning('[TT-BM-HOOK] signature verification failed');

                return response('invalid signature', 403);
            }
        }

        $payload = json_decode($raw, true) ?: [];
        // TikTok wraps business events; normalise both {event, data} and list forms.
        $events = isset($payload['event']) ? [$payload] : (array) ($payload['events'] ?? $payload['data'] ?? []);

        foreach ($events as $ev) {
            $type = (string) ($ev['event'] ?? $ev['type'] ?? '');
            if (! in_array($type, ['new_message', 'message', 'new_conversation'], true)) {
                continue;
            }
            $openId = (string) ($ev['open_id'] ?? $ev['user_openid'] ?? data_get($ev, 'data.open_id', ''));
            $account = \App\Models\TiktokAccount::findByOpenId($openId);
            if (! $account) {
                continue;
            }
            $d = (array) ($ev['data'] ?? $ev);
            try {
                \App\Services\Tiktok\TiktokIngestService::inboundMessage($account, [
                    'conversation_id' => (string) ($d['conversation_id'] ?? ''),
                    'message_id'      => (string) ($d['message_id'] ?? ''),
                    'sender_id'       => (string) ($d['sender_id'] ?? $d['from_id'] ?? ''),
                    'name'            => (string) ($d['sender_name'] ?? $d['sender_nickname'] ?? ''),
                    'avatar'          => (string) ($d['sender_avatar'] ?? $d['sender_avatar_url'] ?? data_get($d, 'sender.avatar_url', '')),
                    'text'            => (string) ($d['text'] ?? data_get($d, 'message.text', '')),
                    'media_type'      => data_get($d, 'message.type') === 'image' ? 'image' : null,
                    'media_url'       => (string) (data_get($d, 'message.image_url') ?? ''),
                    'ts'              => isset($d['create_time']) ? (int) $d['create_time'] : null,
                ]);
            } catch (\Throwable $e) {
                Log::warning('[TT-BM-HOOK] ingest failed: '.$e->getMessage());
            }
        }

        return response('ok', 200);
    }

    /**
     * TikTok Ads "New Lead" webhook (Marketing API). Lead-gen Instant Form
     * submissions arrive here — TikTok has NO bulk lead-download endpoint, so the
     * webhook is the only real-time path. Each lead becomes a Contact tagged
     * `tiktok-lead`, which fires any contact_created flow trigger. Approval-gated;
     * payload shape unverified (re-confirm at business-api.tiktok.com) — fails soft.
     */
    public function leads(Request $request)
    {
        $secret = \App\Services\Tiktok\TiktokBusinessClient::appSecret();
        $raw = $request->getContent();
        if ($secret !== '') {
            $sig = (string) ($request->header('TikTok-Signature', '') ?: $request->header('X-Tt-Signature', ''));
            if ($sig !== '' && ! $this->verifySignature($raw, $sig, $secret)) {
                return response('invalid signature', 403);
            }
        }

        $payload = json_decode($raw, true) ?: [];
        $leads = isset($payload['field_data']) ? [$payload] : (array) ($payload['leads'] ?? $payload['data'] ?? []);

        foreach ($leads as $lead) {
            // Resolve the workspace: the ad's open_id / advertiser maps to a
            // connected TikTok account. Fall back to skipping when unknown.
            $openId  = (string) ($lead['open_id'] ?? $payload['user_openid'] ?? '');
            $account = $openId !== '' ? \App\Models\TiktokAccount::findByOpenId($openId) : null;
            $wsId = $account ? (int) $account->workspace_id : (int) ($lead['workspace_id'] ?? 0);
            if (! $wsId) {
                continue;
            }

            // field_data is a list of {name/field_name, values/value[]} pairs.
            $fields = [];
            foreach ((array) ($lead['field_data'] ?? $lead['fields'] ?? []) as $f) {
                $key = strtolower((string) ($f['name'] ?? $f['field_name'] ?? ''));
                $val = is_array($f['values'] ?? null) ? ($f['values'][0] ?? '') : ($f['value'] ?? '');
                if ($key !== '') {
                    $fields[$key] = (string) $val;
                }
            }
            $name  = $fields['name'] ?? trim(($fields['first_name'] ?? '').' '.($fields['last_name'] ?? ''));
            $email = $fields['email'] ?? '';
            $phone = preg_replace('/\D+/', '', (string) ($fields['phone'] ?? $fields['phone_number'] ?? ''));

            try {
                $contact = \App\Models\Contact::firstOrNew([
                    'workspace_id' => $wsId,
                    'mobile'       => $phone ?: null,
                ]);
                $contact->fill([
                    'name'  => $name ?: ($contact->name ?: 'TikTok lead'),
                    'email' => $email ?: $contact->email,
                    'msg'   => 'Source: TikTok Ads lead form',
                ]);
                $contact->save();
                Log::info('[TT-LEADS] lead captured', ['ws' => $wsId, 'contact' => $contact->id]);
            } catch (\Throwable $e) {
                Log::warning('[TT-LEADS] lead save failed: '.$e->getMessage());
            }
        }

        return response('ok', 200);
    }

    /** HMAC-SHA256 over `<ts>.<rawBody>` keyed by client_secret; timing-safe + fresh. */
    private function verifySignature(string $raw, string $header, string $secret): bool
    {
        if ($header === '') {
            return false;
        }
        $parts = [];
        foreach (explode(',', $header) as $seg) {
            $kv = explode('=', trim($seg), 2);
            if (count($kv) === 2) {
                $parts[trim($kv[0])] = trim($kv[1]);
            }
        }
        $ts = (string) ($parts['t'] ?? '');
        $sig = (string) ($parts['s'] ?? '');
        if ($ts === '' || $sig === '' || ! ctype_digit($ts)) {
            return false;
        }
        // Replay guard: the timestamp is part of the signed payload.
        if (abs(time() - (int) $ts) > self::MAX_SKEW_SECONDS) {
            return false;
        }
        $expected = hash_hmac('sha256', $ts.'.'.$raw, $secret);

        return hash_equals($expected, $sig);
    }

    /** User de-authorized the app → mark every matching account disconnected. */
    private function onAuthorizationRemoved(string $openId): void
    {
        if ($openId === '') {
            return;
        }
        TiktokAccount::where('open_id', $openId)->get()->each(function (TiktokAccount $a) {
            $a->forceFill([
                'status'     => 'needs_reconnect',
                'last_error' => 'TikTok authorization was removed by the user.',
            ])->save();
        });
        Log::info('[TT-HOOK] authorization.removed', ['open_id' => $openId]);
    }

    /** Update the matching post record from a publish lifecycle event. */
    private function onPublishStatus(string $event, array $content): void
    {
        $publishId = (string) ($content['publish_id'] ?? '');
        if ($publishId === '') {
            return;
        }
        $post = TiktokPost::where('publish_id', $publishId)->first();
        if (! $post) {
            return;
        }
        $upd = match ($event) {
            'post.publish.complete' => [
                'status'         => 'published',
                'published_at'   => now(),
                'tiktok_post_id' => (string) (data_get($content, 'post_id') ?: data_get($content, 'publicaly_available_post_id.0') ?: $post->tiktok_post_id),
                'error'          => null,
            ],
            'post.publish.failed' => [
                'status' => 'failed',
                'error'  => mb_substr((string) ($content['reason'] ?? 'publish failed'), 0, 990),
            ],
            'post.publish.no_longer_available' => [
                'status' => 'failed',
                'error'  => 'Post is no longer available (removed or moderated).',
            ],
            default => [],
        };
        if ($upd) {
            $post->forceFill($upd)->save();
            Log::info('[TT-HOOK] '.$event, ['publish_id' => $publishId, 'post' => $post->id]);
        }
    }
}
