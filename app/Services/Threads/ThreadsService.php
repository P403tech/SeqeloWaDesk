<?php

namespace App\Services\Threads;

use App\Models\ThreadsAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Threads (Meta) API client — OAuth token lifecycle + the 2-step publish flow
 * (create container → poll → publish). Mirrors InstagramService's container
 * model. The Threads Graph host is graph.threads.net; the token is passed as
 * the `access_token` query param (Threads convention), not a Bearer header.
 *
 * Docs: https://developers.facebook.com/docs/threads
 */
class ThreadsService
{
    public const API_VERSION = 'v1.0';
    public const GRAPH       = 'https://graph.threads.net';
    public const AUTHORIZE   = 'https://threads.net/oauth/authorize';

    /** Scopes we request for connect (publish + engagement + insights + IG cross-share). */
    public const SCOPES = 'threads_basic,threads_content_publish,threads_manage_replies,threads_read_replies,threads_manage_insights,threads_share_to_instagram';

    private string $base;

    public function __construct(private ThreadsAccount $account)
    {
        $this->base = self::GRAPH . '/' . self::API_VERSION;
    }

    private function token(): string
    {
        return (string) $this->account->access_token;
    }

    private function uid(): string
    {
        return (string) $this->account->threads_user_id;
    }

    // ── OAuth (static — used by the connect controller + token sweeper) ──────

    /** Build the authorization-window URL the user is redirected to. */
    public static function authorizeUrl(string $appId, string $redirectUri, string $state): string
    {
        return self::AUTHORIZE . '?' . http_build_query([
            'client_id'     => $appId,
            'redirect_uri'  => $redirectUri,
            'scope'         => self::SCOPES,
            'response_type' => 'code',
            'state'         => $state,
        ]);
    }

    /** Exchange an authorization code for a SHORT-lived token. Returns ['access_token','user_id'] or null. */
    public static function exchangeCode(string $appId, string $appSecret, string $redirectUri, string $code): ?array
    {
        try {
            $r = Http::asForm()->acceptJson()->timeout(20)->post(self::GRAPH . '/oauth/access_token', [
                'client_id'     => $appId,
                'client_secret' => $appSecret,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => $redirectUri,
                'code'          => $code,
            ]);
            $tok = (string) ($r->json('access_token') ?? '');
            $uid = (string) ($r->json('user_id') ?? '');
            return ($r->successful() && $tok !== '') ? ['access_token' => $tok, 'user_id' => $uid] : null;
        } catch (\Throwable $e) {
            Log::warning('[THREADS] exchangeCode failed: ' . $e->getMessage());
            return null;
        }
    }

    /** Upgrade a short-lived token to a LONG-lived (~60d) one. Returns ['access_token','expires_in'] or null. */
    public static function longLivedToken(string $appSecret, string $shortToken): ?array
    {
        try {
            $r = Http::acceptJson()->timeout(20)->get(self::GRAPH . '/access_token', [
                'grant_type'   => 'th_exchange_token',
                'client_secret' => $appSecret,
                'access_token' => $shortToken,
            ]);
            $tok = (string) ($r->json('access_token') ?? '');
            return ($r->successful() && $tok !== '')
                ? ['access_token' => $tok, 'expires_in' => (int) ($r->json('expires_in') ?? 5183944)]
                : null;
        } catch (\Throwable $e) {
            Log::warning('[THREADS] longLivedToken failed: ' . $e->getMessage());
            return null;
        }
    }

    /** Refresh a long-lived token (must be >=24h old, unexpired). Returns ['access_token','expires_in'] or null. */
    public static function refreshLongLived(string $longToken): ?array
    {
        try {
            $r = Http::acceptJson()->timeout(20)->get(self::GRAPH . '/refresh_access_token', [
                'grant_type'   => 'th_refresh_token',
                'access_token' => $longToken,
            ]);
            $tok = (string) ($r->json('access_token') ?? '');
            return ($r->successful() && $tok !== '')
                ? ['access_token' => $tok, 'expires_in' => (int) ($r->json('expires_in') ?? 5183944)]
                : null;
        } catch (\Throwable $e) {
            Log::warning('[THREADS] refreshLongLived failed: ' . $e->getMessage());
            return null;
        }
    }

    /** Fetch the profile (id/username/name/picture) for a given token. Returns array or null. */
    public static function fetchProfile(string $token): ?array
    {
        try {
            $r = Http::acceptJson()->timeout(20)->get(self::GRAPH . '/' . self::API_VERSION . '/me', [
                'fields'       => 'id,username,name,threads_profile_picture_url',
                'access_token' => $token,
            ]);
            return $r->successful() ? (array) $r->json() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ── Publishing (instance — the 2-step container flow) ────────────────────

    /** POST /{uid}/threads → creation_id. */
    private function createContainer(array $params): array
    {
        try {
            $params['access_token'] = $this->token();
            $r = Http::asForm()->acceptJson()->timeout(60)->post("{$this->base}/{$this->uid()}/threads", $params);
            $id = (string) ($r->json('id') ?? '');
            return ($r->successful() && $id !== '')
                ? ['ok' => true, 'id' => $id]
                : ['ok' => false, 'error' => (string) ($r->json('error.message') ?? 'container create failed')];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** POST /{uid}/threads_publish → published media id. $extra e.g. crossreshare_to_ig. */
    private function publishContainer(string $creationId, array $extra = []): array
    {
        try {
            $r = Http::asForm()->acceptJson()->timeout(60)->post("{$this->base}/{$this->uid()}/threads_publish", array_merge([
                'creation_id'  => $creationId,
                'access_token' => $this->token(),
            ], $extra));
            $id = (string) ($r->json('id') ?? '');
            return ($r->successful() && $id !== '')
                ? ['ok' => true, 'media_id' => $id]
                : ['ok' => false, 'error' => (string) ($r->json('error.message') ?? 'publish failed'), 'creation_id' => $creationId];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** GET a container's status (FINISHED|IN_PROGRESS|ERROR|EXPIRED|PUBLISHED). */
    public function containerStatus(string $containerId): string
    {
        try {
            $r = Http::acceptJson()->timeout(15)->get("{$this->base}/{$containerId}", [
                'fields'       => 'status',
                'access_token' => $this->token(),
            ]);
            // Threads returns {"status":"FINISHED", ...} (some flows use status_code).
            return (string) ($r->json('status') ?? $r->json('status_code') ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** Poll until FINISHED. Meta recommends ~30s for media; text is near-instant. */
    private function pollContainer(string $containerId, int $maxSeconds = 90): bool
    {
        $deadline = time() + max(5, $maxSeconds);
        while (true) {
            $s = $this->containerStatus($containerId);
            if ($s === 'FINISHED') return true;
            if ($s === 'PUBLISHED' || $s === 'ERROR' || $s === 'EXPIRED') return false;
            if (time() >= $deadline) return false;
            sleep(3);
        }
    }

    /** Common create→poll→publish tail. $mediaKind: 'TEXT'|'IMAGE'|'VIDEO'|'CAROUSEL'. */
    private function createPollPublish(array $params, string $mediaKind, array $publishExtra = []): array
    {
        $c = $this->createContainer($params);
        if (! $c['ok']) return $c;
        // TEXT publishes immediately; media needs server-side processing first.
        if ($mediaKind !== 'TEXT' && ! $this->pollContainer($c['id'])) {
            return ['ok' => false, 'error' => 'media container did not finish processing', 'creation_id' => $c['id']];
        }
        return $this->publishContainer($c['id'], $publishExtra) + ['creation_id' => $c['id']];
    }

    /** Publish-endpoint extras derived from options (e.g. cross-share to Instagram). */
    private static function publishExtra(array $opts): array
    {
        return ! empty($opts['cross_to_ig']) ? ['crossreshare_to_ig' => 'true'] : [];
    }

    private function trimText(string $t): string
    {
        return $t !== '' ? mb_substr($t, 0, 500) : '';
    }

    /** Text-only post. opts: link_attachment, cross_to_ig. */
    public function publishText(string $text, array $opts = []): array
    {
        return $this->createPollPublish(array_filter([
            'media_type'      => 'TEXT',
            'text'            => $this->trimText($text),
            'link_attachment' => $opts['link_attachment'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''), 'TEXT', self::publishExtra($opts));
    }

    // ── Replies / moderation (P2) ────────────────────────────────────────────

    /** GET /{media-id}/replies — the top-level replies to one of our posts. */
    public function getReplies(string $mediaId): array
    {
        try {
            $r = Http::acceptJson()->timeout(20)->get("{$this->base}/{$mediaId}/replies", [
                'fields'       => 'id,text,username,timestamp,hide_status,replied_to,is_reply,has_replies',
                'reverse'      => 'false',
                'access_token' => $this->token(),
            ]);
            return $r->successful() ? (array) ($r->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Post a public reply TO a specific reply/post (create container with reply_to_id → publish). */
    public function replyTo(string $replyToId, string $text): array
    {
        return $this->createPollPublish(array_filter([
            'media_type'  => 'TEXT',
            'text'        => $this->trimText($text),
            'reply_to_id' => $replyToId,
        ]), 'TEXT');
    }

    /** POST /{reply-id}/manage_reply — hide (or unhide) a reply (+ its nested replies). */
    public function hideReply(string $replyId, bool $hide = true): bool
    {
        try {
            $r = Http::asForm()->acceptJson()->timeout(15)->post("{$this->base}/{$replyId}/manage_reply", [
                'hide'         => $hide ? 'true' : 'false',
                'access_token' => $this->token(),
            ]);
            return $r->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ── Insights (P3) ────────────────────────────────────────────────────────

    public const MEDIA_METRICS   = 'views,likes,replies,reposts,quotes,shares';
    public const ACCOUNT_METRICS = 'views,likes,replies,reposts,quotes,clicks,followers_count';

    /** Pull one metric number out of an insights `data` array (Total Value or Time Series sum). */
    private static function metricValue(array $data, string $name): int
    {
        foreach ($data as $row) {
            if (($row['name'] ?? '') !== $name) continue;
            if (isset($row['total_value']['value'])) return (int) $row['total_value']['value'];
            $sum = 0;
            foreach ((array) ($row['values'] ?? []) as $v) $sum += (int) ($v['value'] ?? 0);
            return $sum;
        }
        return 0;
    }

    /** GET /{media-id}/insights → [views,likes,replies,reposts,quotes,shares] as ints. */
    public function mediaInsights(string $mediaId): array
    {
        try {
            $r = Http::acceptJson()->timeout(20)->get("{$this->base}/{$mediaId}/insights", [
                'metric'       => self::MEDIA_METRICS,
                'access_token' => $this->token(),
            ]);
            $data = (array) ($r->json('data') ?? []);
            $out = [];
            foreach (explode(',', self::MEDIA_METRICS) as $m) $out[$m] = self::metricValue($data, $m);
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** GET /{user-id}/threads_insights → account totals (views/likes/…/followers_count). */
    public function accountInsights(): array
    {
        try {
            $r = Http::acceptJson()->timeout(20)->get("{$this->base}/{$this->uid()}/threads_insights", [
                'metric'       => self::ACCOUNT_METRICS,
                'access_token' => $this->token(),
            ]);
            $data = (array) ($r->json('data') ?? []);
            $out = [];
            foreach (explode(',', self::ACCOUNT_METRICS) as $m) $out[$m] = self::metricValue($data, $m);
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * GET /{user-id}/threads_insights?metric=follower_demographics&breakdown=…
     * Returns [label => count] sorted desc. $breakdown ∈ country|city|age|gender.
     */
    public function followerDemographics(string $breakdown = 'country', int $top = 6): array
    {
        try {
            $r = Http::acceptJson()->timeout(20)->get("{$this->base}/{$this->uid()}/threads_insights", [
                'metric'       => 'follower_demographics',
                'breakdown'    => $breakdown,
                'access_token' => $this->token(),
            ]);
            $row = collect((array) ($r->json('data') ?? []))->firstWhere('name', 'follower_demographics');
            $results = $row['total_value']['breakdowns'][0]['results'] ?? [];
            $out = [];
            foreach ((array) $results as $res) {
                $label = (string) (($res['dimension_values'][0] ?? '') ?: 'Unknown');
                $out[$label] = (int) ($res['value'] ?? 0);
            }
            arsort($out);
            return array_slice($out, 0, $top, true);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Single image post. image_url = public HTTPS JPEG/PNG. opts: cross_to_ig. */
    public function publishImage(string $imageUrl, string $text = '', array $opts = []): array
    {
        return $this->createPollPublish(array_filter([
            'media_type' => 'IMAGE',
            'image_url'  => $imageUrl,
            'text'       => $this->trimText($text) ?: null,
        ]), 'IMAGE', self::publishExtra($opts));
    }

    /** Single video post. video_url = public HTTPS MP4/MOV. opts: cross_to_ig. */
    public function publishVideo(string $videoUrl, string $text = '', array $opts = []): array
    {
        return $this->createPollPublish(array_filter([
            'media_type' => 'VIDEO',
            'video_url'  => $videoUrl,
            'text'       => $this->trimText($text) ?: null,
        ]), 'VIDEO', self::publishExtra($opts));
    }

    /**
     * Carousel post. $items = [['type'=>'image'|'video','url'=>...], ...] (2–20).
     * Each child is its own container (is_carousel_item=true), then a CAROUSEL
     * parent references them by id.
     */
    public function publishCarousel(array $items, string $text = '', array $opts = []): array
    {
        $childIds = [];
        foreach ($items as $it) {
            $isVideo = strtolower((string) ($it['type'] ?? 'image')) === 'video';
            $params = array_filter([
                'media_type'       => $isVideo ? 'VIDEO' : 'IMAGE',
                'is_carousel_item' => 'true',
                ($isVideo ? 'video_url' : 'image_url') => (string) ($it['url'] ?? ''),
            ]);
            $c = $this->createContainer($params);
            if (! $c['ok']) return $c;
            // give each child time to process before referencing it
            $this->pollContainer($c['id']);
            $childIds[] = $c['id'];
        }
        if (count($childIds) < 2) {
            return ['ok' => false, 'error' => 'carousel needs at least 2 items'];
        }
        return $this->createPollPublish(array_filter([
            'media_type' => 'CAROUSEL',
            'children'   => implode(',', $childIds),
            'text'       => $this->trimText($text) ?: null,
        ]), 'CAROUSEL', self::publishExtra($opts));
    }
}
