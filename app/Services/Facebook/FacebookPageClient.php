<?php

namespace App\Services\Facebook;

use App\Models\FacebookPage;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper over the Facebook Graph API for the Pages channel. Mirrors
 * InstagramService: it REUSES the same Meta app already configured for
 * WhatsApp Embedded Signup (waba_app_id / waba_app_secret) unless a
 * Facebook-specific override is set (fb_app_id / fb_app_secret). All Page calls
 * use the Page access token; OAuth/token helpers are static (no Page instance).
 *
 * P0 scope: OAuth code exchange, short→long-lived USER token, /me/accounts Page
 * enumeration (each Page carries its own non-expiring PAGE token), token debug,
 * and per-Page webhook subscribe. Publishing / comments / insights land in
 * later phases.
 */
class FacebookPageClient
{
    private string $base;

    public string $lastError = '';

    /**
     * Human-readable reason the last listPages() came back empty — surfaced to
     * the operator (not only the log) so a failed connect explains itself.
     * Static because the enumeration entry point listPages() is static.
     */
    public static string $lastEmptyReason = '';

    public function __construct(private FacebookPage $page)
    {
        $this->base = 'https://graph.facebook.com/'.self::version();
        // Bind this page's OWN workspace so appsecret_proof + any credential
        // resolution use THAT workspace's Meta app secret (the app that issued
        // this page token) — critical in webhook context where there is no auth
        // session. A page always knows its workspace; falls back to admin when
        // the workspace has no own app.
        if ($page->workspace_id) {
            self::bindWorkspace((int) $page->workspace_id);
        }
    }

    // ── Shared credential resolution (reuse the WhatsApp Meta app) ──────────

    /** Graph API version — Facebook override, else the WhatsApp app's version, else v23.0. */
    public static function version(): string
    {
        return (string) (SystemSetting::get('fb_graph_version', '')
            ?: (SystemSetting::get('waba_graph_api_version', '') ?: 'v23.0'));
    }

    /**
     * The current workspace's OWN Meta app (manual App ID + Secret), used for
     * Facebook + Instagram connect when the admin has enabled the "own Meta app"
     * option (`meta_allow_manual_app`) and the workspace has filled both. Returns
     * null otherwise → the platform admin's app is used. Facebook and Instagram
     * share one Meta app, so one set covers both. Mirrors the WhatsApp BYO-app.
     *
     * @return array{0:string,1:string}|null
     */
    /**
     * Bound workspace id for contexts with NO auth session (webhook processing).
     * Set it via bindWorkspace() before making Graph calls for a specific page/
     * IG account so appsecret_proof + token exchange use THAT workspace's own
     * Meta app secret (the app that issued the page token), not the admin's.
     */
    private static ?int $boundWorkspaceId = null;

    /** Bind (or clear with null) the workspace whose Meta app should be used. */
    public static function bindWorkspace(?int $workspaceId): void
    {
        self::$boundWorkspaceId = $workspaceId ?: null;
    }

    private static function workspaceMetaApp(): ?array
    {
        // UNGATED (mirrors WhatsApp BYO-app): use the workspace's own Meta app
        // whenever it has entered its keys; falls back to the platform app otherwise.
        // Bound workspace (webhook/no-session) wins; else the logged-in one.
        $ws = self::$boundWorkspaceId
            ? \App\Models\Workspace::find(self::$boundWorkspaceId)
            : auth()->user()?->currentWorkspace;
        if (! $ws) return null;
        $id     = trim((string) ($ws->meta_app_id ?? ''));
        $secret = trim((string) ($ws->meta_app_secret ?? ''));
        return ($id !== '' && $secret !== '') ? [$id, $secret] : null;
    }

    public static function appId(): string
    {
        if ($own = self::workspaceMetaApp()) return $own[0];
        return (string) (SystemSetting::get('fb_app_id', '') ?: SystemSetting::get('waba_app_id', ''));
    }

    public static function appSecret(): string
    {
        if ($own = self::workspaceMetaApp()) return $own[1];
        return (string) (SystemSetting::get('fb_app_secret', '') ?: SystemSetting::get('waba_app_secret', ''));
    }

    private function token(): string
    {
        return (string) $this->page->access_token;
    }

    /**
     * Authenticated Graph request that ALSO attaches Meta's `appsecret_proof`
     * (HMAC-SHA256 of the access token, keyed by the app secret) to every
     * call's query string. Meta rejects server-side calls made with a user or
     * Page token — with error 100 "API calls from the server require an
     * appsecret_proof argument" — whenever the app has "Require app secret
     * proof for server API calls" enabled (on by default for many apps).
     * Centralising it here means every token call carries the proof; the
     * OAuth / debug_token calls use Http::acceptJson() (no token) and are
     * unaffected.
     */
    private static function graph(string $token): \Illuminate\Http\Client\PendingRequest
    {
        $req = Http::withToken($token);
        $secret = self::appSecret();
        if ($token === '' || $secret === '') {
            return $req;
        }
        $proof = hash_hmac('sha256', $token, $secret);

        return $req->withMiddleware(\GuzzleHttp\Middleware::mapRequest(
            function (\Psr\Http\Message\RequestInterface $request) use ($proof) {
                $uri   = $request->getUri();
                $query = $uri->getQuery();
                if (str_contains($query, 'appsecret_proof=')) {
                    return $request; // already present — don't double-add
                }
                $query .= ($query === '' ? '' : '&').'appsecret_proof='.$proof;

                return $request->withUri($uri->withQuery($query));
            }
        ));
    }

    /**
     * Inspect a Graph error and, if it's a token problem (OAuthException code
     * 190 — expired / revoked / password-changed / app removed), flag this Page
     * as needing re-auth so the UI shows a reconnect prompt. Cheap + idempotent.
     */
    private function noteGraphError($error): void
    {
        $error = (array) $error;
        $code = (int) ($error['code'] ?? 0);
        if ($code === 190) {
            try {
                $this->page->forceFill([
                    'status'     => 'expired',
                    'last_error' => mb_substr((string) ($error['message'] ?? 'Token expired — reconnect required.'), 0, 490),
                ])->save();
            } catch (\Throwable $e) {
                // best effort — never let flagging break the send
            }
        }
    }

    // ── Instance calls (Page token) ────────────────────────────────────────

    /** Read this Page's public profile (name, category, followers, picture). */
    public function getProfile(): array
    {
        try {
            $r = self::graph($this->token())->acceptJson()->timeout(15)
                ->get("{$this->base}/{$this->page->page_id}", [
                    'fields' => 'id,name,category,username,fan_count,followers_count,picture.type(large){url}',
                ]);

            return $r->successful() ? (array) $r->json() : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Subscribe this Page to webhook events (both layers are required: the
     * APP-level callback is configured once in the Meta dashboard / admin, and
     * this per-Page subscribed_apps binding tells Meta to deliver THIS Page's
     * events). Without it, no feed/comment/message webhook ever arrives.
     *
     * @return array{ok:bool, error?:string}
     */
    public function subscribeWebhooks(): array
    {
        // 'feed' delivers new posts, comments and reactions; the Messenger
        // fields deliver DMs; 'leadgen' delivers Instant Form submissions
        // (Lead Ads) for both Facebook and Instagram placements. All are
        // Page-level webhook fields.
        //
        // NOTE for already-connected Pages: this list is only applied when a
        // Page is connected or re-subscribed. A Page connected before leadgen
        // was added here keeps its old subscription until the operator clicks
        // "Re-subscribe" — which is why that action exists on the Pages screen.
        $fields = 'feed,mention,messages,messaging_postbacks,message_reactions,messaging_optins,messaging_referrals,leadgen';
        try {
            $r = self::graph($this->token())->acceptJson()->timeout(15)->asForm()
                ->post("{$this->base}/{$this->page->page_id}/subscribed_apps", ['subscribed_fields' => $fields]);
            if ($r->successful()) {
                return ['ok' => true];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'subscribe failed');
            Log::warning('[FB-SUBSCRIBE] failed', ['page' => $this->page->id, 'error' => $this->lastError]);

            return ['ok' => false, 'error' => $this->lastError];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Read back the live subscribed_apps binding for this Page (diagnostics). */
    public function subscribedApps(): array
    {
        try {
            $r = self::graph($this->token())->acceptJson()->timeout(15)
                ->get("{$this->base}/{$this->page->page_id}/subscribed_apps");
            if (! $r->successful()) {
                return ['ok' => false, 'fields' => [], 'error' => (string) ($r->json('error.message') ?? 'read failed')];
            }
            $fields = [];
            foreach ((array) $r->json('data', []) as $app) {
                foreach ((array) ($app['subscribed_fields'] ?? []) as $f) {
                    $fields[] = is_array($f) ? (string) ($f['name'] ?? '') : (string) $f;
                }
            }

            return ['ok' => true, 'fields' => array_values(array_unique(array_filter($fields))), 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'fields' => [], 'error' => $e->getMessage()];
        }
    }

    /** Remove this app's Page subscription (called on disconnect). */
    public function unsubscribeWebhooks(): array
    {
        try {
            $r = self::graph($this->token())->acceptJson()->timeout(15)
                ->delete("{$this->base}/{$this->page->page_id}/subscribed_apps");

            return ['ok' => $r->successful()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Messenger + comments (Page token) ──────────────────────────────────

    /**
     * Send a Messenger message to a user (PSID) as the Page. messaging_type
     * RESPONSE keeps it inside the 24-hour standard messaging window. Returns
     * the message id on success.
     *
     * @return array{ok:bool, mid?:string, error?:string}
     */
    public function sendMessage(string $psid, string $text): array
    {
        try {
            $r = self::graph($this->token())->acceptJson()->timeout(15)
                ->post("{$this->base}/{$this->page->page_id}/messages", [
                    'recipient'      => ['id' => $psid],
                    'messaging_type' => 'RESPONSE',
                    'message'        => ['text' => mb_substr($text, 0, 2000)],
                ]);
            if ($r->successful()) {
                return ['ok' => true, 'mid' => (string) ($r->json('message_id') ?? '')];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'send failed');
            $this->noteGraphError($r->json('error'));

            return ['ok' => false, 'error' => $this->lastError];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Public reply under a comment (or post) as the Page. */
    public function replyComment(string $objectId, string $text): array
    {
        try {
            $r = self::graph($this->token())->acceptJson()->timeout(15)
                ->post("{$this->base}/{$objectId}/comments", ['message' => mb_substr($text, 0, 8000)]);
            if ($r->successful()) {
                return ['ok' => true, 'id' => (string) ($r->json('id') ?? '')];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'reply failed');

            return ['ok' => false, 'error' => $this->lastError];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Private reply to a comment — sends a Messenger DM to the commenter via the
     * Send API. One-time, within the 7-day window Meta allows after a comment.
     */
    public function privateReply(string $commentId, string $text): array
    {
        try {
            $r = self::graph($this->token())->acceptJson()->timeout(15)
                ->post("{$this->base}/{$this->page->page_id}/messages", [
                    'recipient'      => ['comment_id' => $commentId],
                    'messaging_type' => 'RESPONSE',
                    'message'        => ['text' => mb_substr($text, 0, 2000)],
                ]);
            if ($r->successful()) {
                return ['ok' => true, 'mid' => (string) ($r->json('message_id') ?? '')];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'private reply failed');

            return ['ok' => false, 'error' => $this->lastError];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Page-level insights (metrics like page_impressions, page_post_engagements). */
    public function pageInsights(array $metrics, string $period = 'days_28'): array
    {
        try {
            $r = self::graph($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$this->page->page_id}/insights", [
                    'metric' => implode(',', $metrics),
                    'period' => $period,
                ]);

            return $r->successful() ? (array) $r->json('data', []) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Per-post insights (post_impressions, post_engaged_users, …). */
    public function postInsights(string $postId, array $metrics): array
    {
        try {
            $r = self::graph($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$postId}/insights", ['metric' => implode(',', $metrics)]);

            return $r->successful() ? (array) $r->json('data', []) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Recent published posts with basic fields (for an analytics list). */
    public function recentPosts(int $limit = 10): array
    {
        try {
            $r = self::graph($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$this->page->page_id}/published_posts", [
                    'fields' => 'id,message,created_time,permalink_url,full_picture',
                    'limit'  => $limit,
                ]);

            return $r->successful() ? (array) $r->json('data', []) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Messenger user profile (name + avatar) for a PSID that messaged the Page.
     *
     * Two-step, and LOUD on failure. Previously this asked for all four fields
     * at once and returned a bare [] on any refusal, with no log line -- so
     * "Meta refused" and "this user has no name" were indistinguishable, and
     * every thread silently fell back to the "Facebook user" placeholder with
     * nothing in the log to explain it. Graph 400s the WHOLE call when a single
     * requested field is not available for the app's tier, so one unavailable
     * field costs us the name too.
     *
     * Same shape as InstagramService::getSenderProfile(), which already carries
     * this fix for the Instagram side.
     */
    public function getSenderProfile(string $psid): array
    {
        $psid = trim($psid);
        if ($psid === '') return [];

        // Full set first, then the two fields Messenger always returns.
        foreach (['name,first_name,last_name,profile_pic', 'first_name,last_name'] as $i => $fields) {
            try {
                $r = self::graph($this->token())->acceptJson()->timeout(12)
                    ->get("{$this->base}/{$psid}", ['fields' => $fields]);

                if ($r->successful()) {
                    $out = (array) $r->json();
                    if ($i > 0) {
                        Log::info('[FB-PROFILE] resolved on reduced field set', [
                            'page' => $this->page->id, 'psid' => $psid, 'fields' => $fields,
                        ]);
                    }
                    return $out;
                }

                // The diagnostic that was missing. `error.code` 190 = token dead,
                // 100/33 = field or object not visible to this app, 10/200 =
                // permission (pages_messaging not granted at Advanced Access).
                Log::warning('[FB-PROFILE] sender fetch refused', [
                    'page'   => $this->page->id,
                    'psid'   => $psid,
                    'fields' => $fields,
                    'http'   => $r->status(),
                    'error'  => $r->json('error', []),
                    'body'   => mb_substr((string) $r->body(), 0, 400),
                ]);
            } catch (\Throwable $e) {
                Log::warning('[FB-PROFILE] sender fetch threw: '.$e->getMessage(), [
                    'page' => $this->page->id, 'psid' => $psid, 'fields' => $fields,
                ]);
            }
        }

        // FALLBACK — the direct GET /{psid} is blocked for apps without Advanced
        // Access on pages_messaging (error 100/33), but the Page's CONVERSATIONS
        // endpoint still returns each sender's name. Resolve the name from there
        // so threads show the real name instead of "Facebook user" — no App
        // Review needed.
        $viaConv = $this->senderNameFromConversations($psid);
        if ($viaConv !== []) {
            Log::info('[FB-PROFILE] resolved via conversations fallback', [
                'page' => $this->page->id, 'psid' => $psid,
            ]);
            return $viaConv;
        }

        return [];
    }

    /**
     * Resolve a sender's display name from the Page's conversations
     * (participants/senders carry the name even when GET /{psid} is refused at
     * Standard Access). Returns ['name'=>...] or [].
     */
    private function senderNameFromConversations(string $psid): array
    {
        $pageId = (string) ($this->page->page_id ?? '');
        if ($pageId === '' || $psid === '') return [];

        $call = fn (string $tok) => self::graph($tok)->acceptJson()->timeout(12)
            ->get("{$this->base}/{$pageId}/conversations", [
                'platform' => 'messenger',
                'user_id'  => $psid,               // the conversation with THIS person
                'fields'   => 'senders,participants',
                'limit'    => 1,
            ]);

        try {
            $token = (string) $this->page->access_token;
            $r = $call($token);

            // The conversations API is Page-token-only. If the Page stored a
            // user/System-User token (#190 "must be called with a Page Access
            // Token"), self-heal: resolve the Page's own token, persist it (fixes
            // sends too), and retry.
            if (! $r->successful() && (int) $r->json('error.code') === 190) {
                $pt = $this->ensurePageToken();
                if ($pt !== '' && $pt !== $token) {
                    Log::info('[FB-PROFILE] swapped in page token for conversations', ['page' => $this->page->id]);
                    $r = $call($pt);
                }
            }
            if (! $r->successful()) return [];

            foreach ((array) $r->json('data', []) as $conv) {
                $people = array_merge(
                    (array) ($conv['senders']['data'] ?? []),
                    (array) ($conv['participants']['data'] ?? [])
                );
                foreach ($people as $p) {
                    if ((string) ($p['id'] ?? '') === $psid && trim((string) ($p['name'] ?? '')) !== '') {
                        return ['name' => (string) $p['name']];
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[FB-PROFILE] conversations fallback threw: '.$e->getMessage(), [
                'page' => $this->page->id, 'psid' => $psid,
            ]);
        }
        return [];
    }

    /**
     * Ensure the Page has its OWN Page access token stored. A Page connected
     * with a pasted user/System-User token has that token stored, which fails
     * Page-only calls (conversations, some sends). Resolve the Page's own token
     * from /me/accounts (the stored token can still read it) and persist it.
     * Returns the resolved Page token, or the current token if none found.
     */
    private function ensurePageToken(): string
    {
        $token  = (string) $this->page->access_token;
        $pageId = (string) ($this->page->page_id ?? '');
        if ($token === '' || $pageId === '') return $token;
        try {
            $r = self::graph($token)->acceptJson()->timeout(12)
                ->get("{$this->base}/me/accounts", ['fields' => 'id,access_token', 'limit' => 200]);
            if ($r->successful()) {
                foreach ((array) $r->json('data', []) as $p) {
                    if ((string) ($p['id'] ?? '') === $pageId && ! empty($p['access_token'])) {
                        $pt = (string) $p['access_token'];
                        if ($pt !== $token) {
                            $this->page->forceFill(['access_token' => $pt])->save();
                            Log::info('[FB-PROFILE] persisted resolved page token', ['page' => $this->page->id]);
                        }
                        return $pt;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[FB-PROFILE] ensurePageToken threw: '.$e->getMessage(), ['page' => $this->page->id]);
        }
        return $token;
    }

    /** Fetch a single comment (backfill message/from when a webhook omits them). */
    public function getComment(string $commentId): array
    {
        try {
            $r = self::graph($this->token())->acceptJson()->timeout(12)
                ->get("{$this->base}/{$commentId}", ['fields' => 'id,message,from{id,name,picture},created_time,parent{id},attachment']);

            return $r->successful() ? (array) $r->json() : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ── Publishing (Page token) ────────────────────────────────────────────

    /**
     * Publish (or schedule) a post on this Page. Spec keys:
     *   message?     text body
     *   link?        a link post
     *   photos?      array of image URLs (1 = single photo, >1 = multi-photo)
     *   scheduled_ts? unix timestamp → schedules instead of publishing now
     *
     * Single photo posts to /photos; multi-photo uploads each unpublished to
     * collect media_fbids then posts them together to /feed; text/link go to
     * /feed. Returns ['ok'=>bool, 'id'=>string, 'error'=>?string].
     */
    public function publish(array $spec): array
    {
        $pageId = $this->page->page_id;
        $photos = array_values(array_filter(array_map('strval', (array) ($spec['photos'] ?? []))));
        $scheduled = ! empty($spec['scheduled_ts']) ? (int) $spec['scheduled_ts'] : null;
        $message = trim((string) ($spec['message'] ?? ''));
        $link = trim((string) ($spec['link'] ?? ''));

        // Single photo (and not a link post) → /photos directly with a caption.
        if (count($photos) === 1 && $link === '') {
            $params = ['url' => $photos[0], 'caption' => $message];
            if ($scheduled) {
                $params['published'] = 'false';
                $params['scheduled_publish_time'] = $scheduled;
                // Disambiguate SCHEDULED from a plain draft on the /photos endpoint.
                $params['unpublished_content_type'] = 'SCHEDULED';
            }

            return $this->postGraph("{$pageId}/photos", $params, ['post_id', 'id']);
        }

        // Multi-photo → upload each as UNPUBLISHED, collect media_fbid, then /feed.
        $attached = [];
        foreach ($photos as $i => $url) {
            $r = $this->postGraph("{$pageId}/photos", ['url' => $url, 'published' => 'false'], ['id']);
            if (empty($r['ok'])) {
                return $r;
            }
            $attached["attached_media[{$i}]"] = json_encode(['media_fbid' => (string) $r['id']]);
        }

        $params = [];
        if ($message !== '') {
            $params['message'] = $message;
        }
        if ($link !== '') {
            $params['link'] = $link;
        }
        $params += $attached;
        if ($scheduled) {
            $params['published'] = 'false';
            $params['scheduled_publish_time'] = $scheduled;
        }

        return $this->postGraph("{$pageId}/feed", $params, ['id']);
    }

    /** Flip a scheduled/unpublished object live (is_published=true). */
    public function publishNow(string $objectId): array
    {
        return $this->postGraph($objectId, ['is_published' => 'true'], ['id', 'success']);
    }

    /**
     * Publish (or schedule) a feed VIDEO from a public file URL. Meta fetches
     * the file, so $fileUrl must be a public HTTPS URL. Returns the video id.
     */
    public function postVideo(string $fileUrl, string $description = '', ?int $scheduledTs = null): array
    {
        $params = ['file_url' => $fileUrl, 'description' => mb_substr($description, 0, 5000)];
        if ($scheduledTs) {
            $params['published'] = 'false';
            $params['scheduled_publish_time'] = $scheduledTs;
        }

        return $this->postGraph("{$this->page->page_id}/videos", $params, ['id']);
    }

    /**
     * Publish (or schedule) a REEL via the 3-phase video_reels API using a
     * hosted-file transfer (file_url header). Meta must be able to fetch
     * $fileUrl over public HTTPS. Returns the reel video id.
     *
     * @return array{ok:bool, id?:string, error?:string}
     */
    public function postReel(string $fileUrl, string $description = '', ?int $scheduledTs = null): array
    {
        $pageId = $this->page->page_id;
        $token = $this->token();
        try {
            // 1 · START — reserve a video_id + upload_url.
            $start = self::graph($token)->acceptJson()->timeout(30)->asForm()
                ->post("{$this->base}/{$pageId}/video_reels", ['upload_phase' => 'start']);
            if (! $start->successful() || ! $start->json('video_id')) {
                $this->lastError = (string) ($start->json('error.message') ?? 'reel start failed');

                return ['ok' => false, 'error' => $this->lastError];
            }
            $videoId = (string) $start->json('video_id');
            $uploadUrl = (string) $start->json('upload_url');
            if ($uploadUrl === '') {
                $this->lastError = 'reel start returned no upload_url';

                return ['ok' => false, 'error' => $this->lastError];
            }

            // 2 · UPLOAD — hosted transfer: tell Meta where to pull the file from.
            $up = Http::withHeaders([
                'Authorization' => 'OAuth '.$token,
                'file_url'      => $fileUrl,
            ])->timeout(180)->post($uploadUrl);
            if (! $up->successful()) {
                $this->lastError = 'reel upload failed: '.mb_substr($up->body(), 0, 300);

                return ['ok' => false, 'error' => $this->lastError];
            }

            // 2b · WAIT — the rupload 200 only means Meta accepted the transfer
            //      request; for a hosted file_url it downloads async. Finishing
            //      before that completes intermittently fails ("still uploading").
            //      Poll the upload status (bounded) until it's ready.
            for ($i = 0; $i < 20; $i++) {
                $st = self::graph($token)->acceptJson()->timeout(15)
                    ->get("{$this->base}/{$videoId}", ['fields' => 'status']);
                $phase = (string) data_get($st->json(), 'status.uploading_phase.status', '');
                if ($phase === 'complete') {
                    break;
                }
                if ($phase === 'error') {
                    $this->lastError = 'reel upload processing failed';

                    return ['ok' => false, 'error' => $this->lastError];
                }
                usleep(2_000_000); // 2s; ~40s max
            }

            // 3 · FINISH — publish now or schedule. Reel captions cap at ~2200.
            $finish = ['upload_phase' => 'finish', 'video_id' => $videoId, 'description' => mb_substr($description, 0, 2200)];
            if ($scheduledTs) {
                $finish['video_state'] = 'SCHEDULED';
                $finish['scheduled_publish_time'] = $scheduledTs;
            } else {
                $finish['video_state'] = 'PUBLISHED';
            }
            $done = self::graph($token)->asForm()->acceptJson()->timeout(30)
                ->post("{$this->base}/{$pageId}/video_reels", $finish);
            if ($done->successful()) {
                return ['ok' => true, 'id' => $videoId, 'error' => null];
            }
            $this->lastError = (string) ($done->json('error.message') ?? 'reel finish failed');

            return ['ok' => false, 'error' => $this->lastError];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Delete a published post. */
    public function deletePost(string $objectId): array
    {
        try {
            $r = self::graph($this->token())->acceptJson()->timeout(15)->delete("{$this->base}/{$objectId}");

            return ['ok' => $r->successful()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Shared form POST → normalized result reading the first present id key. */
    private function postGraph(string $path, array $params, array $idKeys): array
    {
        try {
            $r = self::graph($this->token())->asForm()->acceptJson()->timeout(30)
                ->post("{$this->base}/{$path}", $params);
            if ($r->successful()) {
                $id = '';
                foreach ($idKeys as $k) {
                    if ($r->json($k) !== null) {
                        $id = (string) $r->json($k);
                        break;
                    }
                }

                return ['ok' => true, 'id' => $id, 'error' => null];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'request failed');
            $this->noteGraphError($r->json('error'));

            return ['ok' => false, 'id' => '', 'error' => $this->lastError];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return ['ok' => false, 'id' => '', 'error' => $e->getMessage()];
        }
    }

    // ── Static OAuth + token helpers (no Page instance) ────────────────────

    /**
     * Exchange an OAuth code for a short-lived USER access token (redirect
     * flow). The embedded-signup popup exchanges WITHOUT a redirect_uri — pass
     * '' to omit it.
     *
     * @return array{ok:bool, access_token?:string, expires_in?:int, error?:string}
     */
    public static function exchangeCode(string $code, string $redirectUri): array
    {
        $appId = self::appId();
        $secret = self::appSecret();
        if ($appId === '' || $secret === '' || $code === '') {
            return ['ok' => false, 'error' => 'Facebook Meta app credentials are not configured.'];
        }
        $params = ['client_id' => $appId, 'client_secret' => $secret, 'code' => $code];
        if ($redirectUri !== '') {
            $params['redirect_uri'] = $redirectUri;
        }
        try {
            $r = Http::acceptJson()->timeout(15)
                ->get('https://graph.facebook.com/'.self::version().'/oauth/access_token', $params);
            if ($r->successful() && $r->json('access_token')) {
                return ['ok' => true, 'access_token' => (string) $r->json('access_token'), 'expires_in' => (int) $r->json('expires_in', 0)];
            }

            return ['ok' => false, 'error' => (string) ($r->json('error.message') ?? 'token exchange failed')];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Upgrade a short-lived USER token to a ~60-day long-lived token. MUST run
     * BEFORE /me/accounts so the derived PAGE tokens never expire — the single
     * most common Facebook-integration bug is skipping this step.
     *
     * @return array{ok:bool, access_token?:string, expires_in?:int, error?:string}
     */
    public static function extendUserToken(string $token): array
    {
        $appId = self::appId();
        $secret = self::appSecret();
        if ($appId === '' || $secret === '' || $token === '') {
            return ['ok' => false, 'error' => 'missing app credentials or token'];
        }
        try {
            $r = Http::acceptJson()->timeout(15)
                ->get('https://graph.facebook.com/'.self::version().'/oauth/access_token', [
                    'grant_type'        => 'fb_exchange_token',
                    'client_id'         => $appId,
                    'client_secret'     => $secret,
                    'fb_exchange_token' => $token,
                ]);
            if ($r->successful() && $r->json('access_token')) {
                return ['ok' => true, 'access_token' => (string) $r->json('access_token'), 'expires_in' => (int) $r->json('expires_in', 5184000)];
            }

            return ['ok' => false, 'error' => (string) ($r->json('error.message') ?? 'long-lived exchange failed')];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Enumerate the Pages the authenticated user manages. Each row carries its
     * OWN Page access token (non-expiring when derived from a long-lived user
     * token) plus the granted tasks. This is the account→Pages fan-out.
     *
     * @return array<int, array<string,mixed>>  raw Page rows (id,name,category,access_token,tasks,fan_count,username,picture)
     */
    public static function listPages(string $userToken): array
    {
        self::$lastEmptyReason = '';
        if ($userToken === '') {
            return [];
        }
        try {
            $out = [];
            $url = 'https://graph.facebook.com/'.self::version().'/me/accounts';
            $params = ['fields' => 'id,name,category,access_token,tasks,fan_count,username,picture.type(large){url}', 'limit' => 100];
            // Follow pagination so accounts with many Pages are fully listed.
            for ($guard = 0; $guard < 10 && $url !== ''; $guard++) {
                $r = self::graph($userToken)->acceptJson()->timeout(20)->get($url, $params);
                if (! $r->successful()) {
                    // A non-2xx used to `break` silently, so EVERY failure —
                    // expired token, missing scope, rate limit — surfaced to the
                    // operator as the identical "No Facebook Pages were found",
                    // and nothing was written to the log. Record what Meta
                    // actually said; that is the difference between diagnosing
                    // this and guessing at it.
                    $gerr = (array) ($r->json('error') ?? []);
                    self::$lastEmptyReason = 'Meta rejected the Pages lookup (HTTP '.$r->status().'): '
                        .(string) ($gerr['message'] ?? mb_substr((string) $r->body(), 0, 200));
                    Log::warning('[FB-PAGES] /me/accounts HTTP '.$r->status(), [
                        'graph_version' => self::version(),
                        'app_id'        => self::appId(),
                        'error'         => $r->json('error') ?? mb_substr((string) $r->body(), 0, 500),
                    ]);
                    break;
                }
                foreach ((array) $r->json('data', []) as $p) {
                    $out[] = (array) $p;
                }
                $url = (string) ($r->json('paging.next') ?? '');
                $params = []; // the `next` URL already carries the query string
            }

            // An EMPTY-but-successful list is the reported symptom: consent
            // says "1 Page selected", /me/accounts returns nothing. That
            // happens when the Pages were granted to a BUSINESS (Login for
            // Business / config_id) rather than to the user directly, so they
            // are not among the Pages the user personally administers.
            // Diagnose it here — read-only, no behaviour change — so one
            // connect attempt tells us exactly where the Pages actually live.
            if (! $out) {
                self::logEmptyPagesDiagnosis($userToken);
                // Fall back to the Pages owned by the user's BUSINESSES. This
                // is the reported failure: the merchant ticks a Business in the
                // consent dialog (briefcase icon), grants every Page
                // permission, Meta confirms "connected" — and /me/accounts is
                // still empty, because the Pages belong to the Business rather
                // than to the person. Verified against Meta: those consent-list
                // ids reject `category` ("nonexisting field") and accept
                // `verification_status`, i.e. they are Businesses, not Pages.
                $out = self::listBusinessPages($userToken);

                // Last resort: Login-for-Business (config_id) connects return a
                // business SYSTEM-USER token whose Pages surface in NEITHER
                // /me/accounts NOR /me/businesses. The debug_token
                // granular_scopes still carry the exact Page ids the user
                // granted, so fetch each of those Pages' tokens directly. This
                // is the screenshot case: many Pages + Businesses granted in the
                // dialog, yet "No Pages found".
                if (! $out) {
                    $out = self::listPagesFromGrantedIds($userToken);
                }
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('[FB-PAGES] listPages failed: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Pages reachable through the user's BUSINESSES, shaped exactly like
     * /me/accounts rows so storePages() can consume them unchanged.
     *
     * Why this is needed: /me/accounts returns only Pages the person
     * administers directly. When a merchant connects via a Business Portfolio
     * — the briefcase entries in Meta's consent dialog — the Pages are held by
     * the Business, so that endpoint is legitimately empty even though consent
     * granted every Page permission.
     *
     * Two edges matter:
     *   owned_pages  — Pages the Business owns outright
     *   client_pages — Pages another Business owns but this one manages (agencies)
     *
     * Neither edge returns a Page access token, and storePages() skips any row
     * without one, so the token is fetched per Page. That call also acts as the
     * access check: no role on the Page → no token → the Page is skipped rather
     * than stored half-connected.
     *
     * Requires `business_management`, already in FacebookConnectController::SCOPES,
     * so this needs no new permission and no re-review.
     *
     * @return array<int, array<string,mixed>>
     */
    private static function listBusinessPages(string $userToken): array
    {
        $v = self::version();
        $pages = [];

        try {
            $bz = self::graph($userToken)->acceptJson()->timeout(20)
                ->get('https://graph.facebook.com/'.$v.'/me/businesses', ['limit' => 50]);
            if (! $bz->successful()) {
                Log::warning('[FB-PAGES] /me/businesses failed', [
                    'status' => $bz->status(), 'error' => $bz->json('error'),
                ]);

                return [];
            }

            foreach ((array) $bz->json('data', []) as $b) {
                $bizId = (string) ($b['id'] ?? '');
                if ($bizId === '') {
                    continue;
                }

                foreach (['owned_pages', 'client_pages'] as $edge) {
                    $r = self::graph($userToken)->acceptJson()->timeout(20)
                        ->get('https://graph.facebook.com/'.$v.'/'.$bizId.'/'.$edge, [
                            'fields' => 'id,name,category,username,fan_count,picture.type(large){url}',
                            'limit'  => 100,
                        ]);
                    if (! $r->successful()) {
                        Log::warning('[FB-PAGES] business edge failed', [
                            'business' => $bizId, 'edge' => $edge,
                            'status' => $r->status(), 'error' => $r->json('error'),
                        ]);
                        continue;
                    }

                    foreach ((array) $r->json('data', []) as $p) {
                        $pid = (string) ($p['id'] ?? '');
                        // A Page can appear under both edges, and under more
                        // than one Business — keep the first hit only.
                        if ($pid === '' || isset($pages[$pid])) {
                            continue;
                        }

                        $tok = self::pageTokenFor($pid, $userToken);
                        if ($tok === '') {
                            Log::warning('[FB-PAGES] no Page token — skipped', [
                                'page' => $pid, 'name' => $p['name'] ?? null,
                                'business' => $bizId, 'edge' => $edge,
                            ]);
                            continue;
                        }

                        $p['access_token'] = $tok;
                        // /me/accounts carries `tasks`; the business edges do
                        // not. Absent rather than guessed — upsertPage stores
                        // whatever is here and an invented list would misreport
                        // what the operator can actually do.
                        $pages[$pid] = (array) $p;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[FB-PAGES] listBusinessPages failed: '.$e->getMessage());
        }

        if ($pages) {
            Log::info('[FB-PAGES] recovered via business edges', [
                'count' => count($pages), 'pages' => array_keys($pages),
            ]);
        }

        return array_values($pages);
    }

    /** A single Page's access token, or '' when the user holds no role on it. */
    private static function pageTokenFor(string $pageId, string $userToken): string
    {
        try {
            $r = self::graph($userToken)->acceptJson()->timeout(15)
                ->get('https://graph.facebook.com/'.self::version().'/'.$pageId, [
                    'fields' => 'access_token',
                ]);

            return $r->successful() ? (string) ($r->json('access_token') ?? '') : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Third recovery tier for Login-for-Business (config_id) connects. That
     * flow issues a business SYSTEM-USER token whose Pages appear in neither
     * /me/accounts nor /me/businesses, so both earlier tiers come back empty
     * even though consent granted every Page permission. The token's
     * debug_token `granular_scopes` DO carry the exact granted Page ids
     * (target_ids per Page-level scope); fetch each Page's own token from
     * those ids. Rows are shaped like /me/accounts so storePages() consumes
     * them unchanged (a row with no access_token is skipped there).
     *
     * @return array<int, array<string,mixed>>
     */
    private static function listPagesFromGrantedIds(string $userToken): array
    {
        $grant = self::debugToken($userToken);
        $granular = (array) ($grant['granular_scopes'] ?? []);
        if (! $granular) {
            return [];
        }

        // Only PAGE-level scopes carry Page ids in target_ids;
        // business_management's target_ids are Business ids (they 404 as Pages).
        $pageScopes = [
            'pages_show_list', 'pages_messaging', 'pages_manage_metadata',
            'pages_manage_posts', 'pages_manage_engagement',
            'pages_read_engagement', 'pages_read_user_content', 'read_insights',
        ];
        $ids = [];
        foreach ($granular as $g) {
            if (! in_array((string) ($g['scope'] ?? ''), $pageScopes, true)) {
                continue;
            }
            foreach ((array) ($g['target_ids'] ?? []) as $tid) {
                $tid = (string) $tid;
                if ($tid !== '') {
                    $ids[$tid] = true;
                }
            }
        }
        $ids = array_keys($ids);
        if (! $ids) {
            Log::warning('[FB-PAGES] granular_scopes carried no Page target_ids', [
                'scopes_seen' => array_values(array_filter(array_map(
                    static fn ($g) => (string) ($g['scope'] ?? ''), $granular
                ))),
            ]);
            self::$lastEmptyReason = 'Meta returned no per-Page grant on this login. In the Facebook dialog choose "Opt in to current Pages" and tick at least one Page (not only a Business).';

            return [];
        }

        $v = self::version();
        $pages = [];
        $noToken = [];
        foreach ($ids as $pid) {
            try {
                $r = self::graph($userToken)->acceptJson()->timeout(15)
                    ->get('https://graph.facebook.com/'.$v.'/'.$pid, [
                        'fields' => 'id,name,category,username,fan_count,access_token,picture.type(large){url}',
                    ]);
                if (! $r->successful() || ! $r->json('access_token')) {
                    $noToken[] = $pid;

                    continue;
                }
                $pages[$pid] = (array) $r->json();
            } catch (\Throwable $e) {
                $noToken[] = $pid;
            }
        }

        Log::info('[FB-PAGES] granted-ids recovery', [
            'granted_page_ids' => count($ids),
            'recovered'        => count($pages),
            'no_token'         => $noToken,
        ]);

        if (! $pages) {
            self::$lastEmptyReason = 'Meta granted '.count($ids).' Page permission(s) but returned no Page access token for any — the connecting user has no admin role on those Pages, or they are held by a Business this app is not installed in.';
        }

        return array_values($pages);
    }

    /**
     * Why did /me/accounts come back empty? Logs the token's real identity and
     * granted scopes, then looks for the Pages via the business endpoints.
     * Purely diagnostic: nothing here changes what listPages() returns.
     */
    private static function logEmptyPagesDiagnosis(string $userToken): void
    {
        $v = self::version();
        $ctx = ['graph_version' => $v, 'app_id' => self::appId()];

        try {
            // 1. Who is this token, and what was actually granted? `scopes` is
            //    the authoritative list — the consent screen can show a
            //    permission that was then declined.
            $dbg = Http::acceptJson()->timeout(15)->get('https://graph.facebook.com/'.$v.'/debug_token', [
                'input_token'  => $userToken,
                'access_token' => self::appId().'|'.self::appSecret(),
            ]);
            $d = (array) ($dbg->json('data') ?? []);
            $ctx['token_app_id']  = $d['app_id']    ?? null;
            $ctx['token_user_id'] = $d['user_id']   ?? null;
            $ctx['token_type']    = $d['type']      ?? null;
            $ctx['token_valid']   = $d['is_valid']  ?? null;
            $ctx['granted_scopes'] = implode(',', (array) ($d['scopes'] ?? []));
            // Present only on Login-for-Business tokens — its presence proves
            // which flow produced this token.
            $ctx['granular_scopes'] = json_encode($d['granular_scopes'] ?? null);
        } catch (\Throwable $e) {
            $ctx['debug_token_error'] = mb_substr($e->getMessage(), 0, 160);
        }

        // 2. Do the Pages show up under a business the user belongs to? If any
        //    of these return rows while /me/accounts is empty, the fix is to
        //    read Pages from the business rather than from the user.
        foreach (['/me/businesses' => 'businesses', '/me/accounts?type=page' => 'accounts_typed'] as $path => $label) {
            try {
                $r = self::graph($userToken)->acceptJson()->timeout(15)
                    ->get('https://graph.facebook.com/'.$v.$path);
                $ctx[$label] = $r->successful()
                    ? count((array) $r->json('data', []))
                    : ('HTTP '.$r->status().' '.json_encode($r->json('error')));
            } catch (\Throwable $e) {
                $ctx[$label] = 'ex: '.mb_substr($e->getMessage(), 0, 120);
            }
        }

        // 3. For each business, the Pages it owns / has client access to.
        try {
            $bz = self::graph($userToken)->acceptJson()->timeout(15)
                ->get('https://graph.facebook.com/'.$v.'/me/businesses');
            foreach ((array) $bz->json('data', []) as $b) {
                $bid = (string) ($b['id'] ?? '');
                if ($bid === '') {
                    continue;
                }
                foreach (['owned_pages', 'client_pages'] as $edge) {
                    $r = self::graph($userToken)->acceptJson()->timeout(15)
                        ->get('https://graph.facebook.com/'.$v.'/'.$bid.'/'.$edge, ['fields' => 'id,name', 'limit' => 25]);
                    $ctx['biz_'.$bid.'_'.$edge] = $r->successful()
                        ? count((array) $r->json('data', []))
                        : ('HTTP '.$r->status());
                }
            }
        } catch (\Throwable $e) {
            $ctx['businesses_error'] = mb_substr($e->getMessage(), 0, 160);
        }

        Log::warning('[FB-PAGES] /me/accounts returned ZERO Pages — diagnosis', $ctx);
    }

    /**
     * Resolve a single Page from a manually-pasted PAGE access token. A Page
     * token's /me returns the Page node itself. Used by the "manual" connect
     * path. Returns the Page fields + the granted tasks, or [] on failure.
     */
    public static function pageFromToken(string $pageToken): array
    {
        if ($pageToken === '') {
            return [];
        }
        try {
            $r = self::graph($pageToken)->acceptJson()->timeout(15)
                ->get('https://graph.facebook.com/'.self::version().'/me', [
                    'fields' => 'id,name,category,username,fan_count,tasks,picture.type(large){url}',
                ]);

            if ($r->successful() && $r->json('id')) {
                \Illuminate\Support\Facades\Log::info('[FB-CONNECT] pageFromToken resolved', [
                    'page_id' => $r->json('id'), 'name' => $r->json('name'), 'token_head' => substr($pageToken, 0, 10),
                ]);
                return (array) $r->json();
            }

            // Not a PAGE token — /me on a USER or SYSTEM-USER token has no
            // `category` field ((#100) "nonexisting field (category)"). A
            // System-User token IS the recommended one to generate (never
            // expires), so instead of rejecting it we resolve the Page(s) it
            // manages via /me/accounts — each row carries its OWN page access
            // token, which is what we store + send with. This lets a user paste
            // the exact token Meta's "Generate token" flow gives them.
            $acc = self::graph($pageToken)->acceptJson()->timeout(15)
                ->get('https://graph.facebook.com/'.self::version().'/me/accounts', [
                    'fields' => 'id,name,category,username,fan_count,tasks,access_token,picture.type(large){url}',
                    'limit'  => 100,
                ]);
            if ($acc->successful() && !empty($acc->json('data'))) {
                $pages = (array) $acc->json('data');
                $page  = (array) $pages[0]; // first assigned Page
                \Illuminate\Support\Facades\Log::info('[FB-CONNECT] pageFromToken resolved via /me/accounts', [
                    'page_id' => $page['id'] ?? null, 'name' => $page['name'] ?? null,
                    'pages_available' => count($pages), 'token_head' => substr($pageToken, 0, 10),
                ]);
                // The Page's OWN access token — connectManual stores this rather
                // than the pasted System-User token so sends act as the Page.
                if (!empty($page['access_token'])) {
                    $page['page_token'] = (string) $page['access_token'];
                    unset($page['access_token']);
                }
                return $page;
            }

            // Log WHY it failed so a manual connect that "does nothing" is
            // diagnosable — the usual cause is a WhatsApp/System-User token (wrong
            // permissions) OR a token from a DIFFERENT Meta app than the platform's
            // (appsecret_proof mismatch → "Invalid OAuth access token signature").
            self::$lastTokenError = (string) ($r->json('error.message') ?? ('HTTP ' . $r->status()));
            \Illuminate\Support\Facades\Log::warning('[FB-CONNECT] pageFromToken FAILED', [
                'http'        => $r->status(),
                'error'       => $r->json('error'),
                'token_head'  => substr($pageToken, 0, 10),
                'token_len'   => strlen($pageToken),
                'platform_app_id' => self::appId(),
                'hint'        => 'Token reached Graph but no Page resolved. Usual causes: (a) the System-User has no Facebook Page assigned as an asset (Business Settings → System Users → Add assets → your Page → full control), or (b) the token is from a different Meta app than the one saved in Settings → Meta app (appsecret_proof mismatch).',
            ]);
            return [];
        } catch (\Throwable $e) {
            self::$lastTokenError = $e->getMessage();
            \Illuminate\Support\Facades\Log::warning('[FB-CONNECT] pageFromToken threw', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /** Set by pageFromToken() on failure so the connect controller can surface Meta's real reason. */
    public static string $lastTokenError = '';

    /** Validate any token — is_valid, scopes, expiry (diagnostics / re-auth). */
    public static function debugToken(string $token): array
    {
        $appId = self::appId();
        $secret = self::appSecret();
        if ($token === '' || $appId === '' || $secret === '') {
            return [];
        }
        try {
            $r = Http::acceptJson()->timeout(15)
                ->get('https://graph.facebook.com/'.self::version().'/debug_token', [
                    'input_token'  => $token,
                    'access_token' => $appId.'|'.$secret, // app access token
                ]);

            return $r->successful() ? (array) $r->json('data', []) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ── Sender actions & message tags ──────────────────────────────────────

    /**
     * Shared Messenger Send-API POST (JSON body, Page token). Normalizes the
     * result and flags token errors via noteGraphError; surfaces whichever id
     * keys Graph returns (message_id → mid, attachment_id, recipient_id).
     *
     * @return array{ok:bool, mid?:string, attachment_id?:string, recipient_id?:string, error?:string}
     */
    private function messagesSend(array $body): array
    {
        try {
            $r = self::graph($this->token())->acceptJson()->timeout(20)
                ->post("{$this->base}/{$this->page->page_id}/messages", $body);
            if ($r->successful()) {
                $out = ['ok' => true];
                if ($r->json('message_id') !== null) {
                    $out['mid'] = (string) $r->json('message_id');
                }
                if ($r->json('attachment_id') !== null) {
                    $out['attachment_id'] = (string) $r->json('attachment_id');
                }
                if ($r->json('recipient_id') !== null) {
                    $out['recipient_id'] = (string) $r->json('recipient_id');
                }

                return $out;
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'send failed');
            $this->noteGraphError($r->json('error'));

            return ['ok' => false, 'error' => $this->lastError];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send a sender action to a PSID: typing_on | typing_off | mark_seen. Carries
     * no message/messaging_type. Only valid inside the 24h window (and does not
     * extend it); the recipient must be signed in for the indicator to show.
     *
     * @return array{ok:bool, recipient_id?:string, error?:string}
     */
    public function sendSenderAction(string $psid, string $action): array
    {
        return $this->messagesSend([
            'recipient'     => ['id' => $psid],
            'sender_action' => $action,
        ]);
    }

    /**
     * React (emoji) or, when $emoji is null, un-react to a USER message the Page
     * received ($mid from the messages webhook). $emoji is the literal UTF-8
     * character. Only inside the 24h standard messaging window.
     *
     * @return array{ok:bool, error?:string}
     */
    public function reactToMessage(string $psid, string $mid, ?string $emoji = null): array
    {
        $payload = $emoji === null ? ['message_id' => $mid] : ['message_id' => $mid, 'reaction' => $emoji];

        return $this->messagesSend([
            'recipient'     => ['id' => $psid],
            'sender_action' => $emoji === null ? 'unreact' : 'react',
            'payload'       => $payload,
        ]);
    }

    /**
     * Send a message OUTSIDE the 24h window under a message tag. Only HUMAN_AGENT
     * (7-day human-reply window) remains usable — the *_UPDATE tags were
     * deprecated 2026-04-27 and now return error 100. $message is a Send-API
     * message object ({text:...} or {attachment:...}).
     *
     * @return array{ok:bool, mid?:string, error?:string}
     */
    public function sendTaggedMessage(string $psid, array $message, string $tag): array
    {
        return $this->messagesSend([
            'recipient'      => ['id' => $psid],
            'messaging_type' => 'MESSAGE_TAG',
            'tag'            => $tag,
            'message'        => $message,
        ]);
    }

    /**
     * Send a media attachment (image|video|audio|file) from a public HTTPS URL
     * Meta fetches server-side. is_reusable=true also returns a reusable
     * attachment_id in the same response. Within the 24h window.
     *
     * @return array{ok:bool, mid?:string, attachment_id?:string, error?:string}
     */
    public function sendAttachment(string $psid, string $type, string $url, bool $isReusable = false): array
    {
        $payload = ['url' => $url];
        if ($isReusable) {
            $payload['is_reusable'] = true;
        }

        return $this->messagesSend([
            'recipient'      => ['id' => $psid],
            'messaging_type' => 'RESPONSE',
            'message'        => ['attachment' => ['type' => $type, 'payload' => $payload]],
        ]);
    }

    /**
     * Pre-upload a REUSABLE media asset (no recipient) so the same file can be
     * sent to many recipients without re-upload. Returns the attachment_id to
     * feed into sendAttachmentById / sendMediaTemplate.
     *
     * @return array{ok:bool, attachment_id?:string, error?:string}
     */
    public function uploadAttachment(string $type, string $url): array
    {
        try {
            $r = self::graph($this->token())->acceptJson()->timeout(30)
                ->post("{$this->base}/{$this->page->page_id}/message_attachments", [
                    'message' => ['attachment' => ['type' => $type, 'payload' => ['is_reusable' => true, 'url' => $url]]],
                ]);
            if ($r->successful()) {
                return ['ok' => true, 'attachment_id' => (string) ($r->json('attachment_id') ?? '')];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'attachment upload failed');
            $this->noteGraphError($r->json('error'));

            return ['ok' => false, 'error' => $this->lastError];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send media by a previously-uploaded attachment_id (from uploadAttachment or
     * a prior is_reusable send). $type must match the uploaded asset. Within 24h.
     *
     * @return array{ok:bool, mid?:string, error?:string}
     */
    public function sendAttachmentById(string $psid, string $type, string $attachmentId): array
    {
        return $this->messagesSend([
            'recipient'      => ['id' => $psid],
            'messaging_type' => 'RESPONSE',
            'message'        => ['attachment' => ['type' => $type, 'payload' => ['attachment_id' => $attachmentId]]],
        ]);
    }

    /**
     * Send text with up to 13 quick replies (text/user_phone_number/user_email).
     * A tap returns message.quick_reply.payload in the messages webhook. Within 24h.
     *
     * @return array{ok:bool, mid?:string, error?:string}
     */
    public function sendQuickReplies(string $psid, string $text, array $replies): array
    {
        return $this->messagesSend([
            'recipient'      => ['id' => $psid],
            'messaging_type' => 'RESPONSE',
            'message'        => [
                'text'          => mb_substr($text, 0, 2000),
                'quick_replies' => array_slice(array_values($replies), 0, 13),
            ],
        ]);
    }

    /**
     * Send a button template (≤3 buttons: web_url | postback | phone_number).
     * postback taps arrive on the messaging_postbacks webhook. Within 24h.
     *
     * @return array{ok:bool, mid?:string, error?:string}
     */
    public function sendButtonTemplate(string $psid, string $text, array $buttons): array
    {
        return $this->messagesSend([
            'recipient'      => ['id' => $psid],
            'messaging_type' => 'RESPONSE',
            'message'        => ['attachment' => ['type' => 'template', 'payload' => [
                'template_type' => 'button',
                'text'          => mb_substr($text, 0, 640),
                'buttons'       => array_slice(array_values($buttons), 0, 3),
            ]]],
        ]);
    }

    /**
     * Send a generic (horizontal-scroll carousel) template with 1–10 elements,
     * each up to 3 buttons + optional default_action. Within 24h.
     *
     * @return array{ok:bool, mid?:string, error?:string}
     */
    public function sendGenericTemplate(string $psid, array $elements): array
    {
        return $this->messagesSend([
            'recipient'      => ['id' => $psid],
            'messaging_type' => 'RESPONSE',
            'message'        => ['attachment' => ['type' => 'template', 'payload' => [
                'template_type' => 'generic',
                'elements'      => array_slice(array_values($elements), 0, 10),
            ]]],
        ]);
    }

    /**
     * Send a media template (exactly one image|video element) — pass an
     * attachment_id or a Facebook-hosted media URL, with ≤3 overlay buttons.
     * Within 24h.
     *
     * @return array{ok:bool, mid?:string, error?:string}
     */
    public function sendMediaTemplate(string $psid, string $mediaType, string $attachmentIdOrUrl, array $buttons = []): array
    {
        $element = ['media_type' => $mediaType];
        if (preg_match('#^https?://#i', $attachmentIdOrUrl)) {
            $element['url'] = $attachmentIdOrUrl;
        } else {
            $element['attachment_id'] = $attachmentIdOrUrl;
        }
        if ($buttons !== []) {
            $element['buttons'] = array_slice(array_values($buttons), 0, 3);
        }

        return $this->messagesSend([
            'recipient'      => ['id' => $psid],
            'messaging_type' => 'RESPONSE',
            'message'        => ['attachment' => ['type' => 'template', 'payload' => [
                'template_type' => 'media',
                'elements'      => [$element],
            ]]],
        ]);
    }

    /**
     * Send a One-Time Notification opt-in request button. On tap you receive a
     * messaging_optins webhook carrying a single-use one_time_notif_token.
     * Beta feature (advanced_permission) — verify availability before wiring.
     *
     * @return array{ok:bool, mid?:string, error?:string}
     */
    public function sendOtnRequest(string $psid, string $title, string $payload): array
    {
        return $this->messagesSend([
            'recipient' => ['id' => $psid],
            'message'   => ['attachment' => ['type' => 'template', 'payload' => [
                'template_type' => 'one_time_notif_req',
                'title'         => mb_substr($title, 0, 65),
                'payload'       => $payload,
            ]]],
        ]);
    }

    /**
     * Send exactly ONE message outside the 24h window using a one_time_notif_token
     * captured from the opt-in webhook. The token is consumed after a single send.
     *
     * @return array{ok:bool, mid?:string, error?:string}
     */
    public function sendOtnMessage(string $token, array $message): array
    {
        return $this->messagesSend([
            'recipient' => ['one_time_notif_token' => $token],
            'message'   => $message,
        ]);
    }

    /**
     * Send a recurring-notifications (marketing) opt-in prompt. On opt-in you get
     * a messaging_optin webhook with a notification_messages_token + expiry.
     * Meta's recommended replacement for deprecated tags/OTN (advanced_permission).
     *
     * @return array{ok:bool, mid?:string, error?:string}
     */
    public function sendRecurringNotificationRequest(string $psid, string $title, string $frequency, string $payload, ?string $imageUrl = null): array
    {
        $tpl = [
            'template_type'                   => 'notification_messages',
            'title'                           => mb_substr($title, 0, 65),
            'notification_messages_frequency' => $frequency,
            'payload'                         => $payload,
        ];
        if ($imageUrl !== null && $imageUrl !== '') {
            $tpl['image_url'] = $imageUrl;
        }

        return $this->messagesSend([
            'recipient' => ['id' => $psid],
            'message'   => ['attachment' => ['type' => 'template', 'payload' => $tpl]],
        ]);
    }

    /**
     * Send a marketing message via a notification_messages_token, outside the 24h
     * window. Limit 1/day/token at the opted-in cadence; token expires and must be
     * re-requested (advanced_permission).
     *
     * @return array{ok:bool, mid?:string, error?:string}
     */
    public function sendRecurringNotification(string $token, array $message): array
    {
        return $this->messagesSend([
            'recipient' => ['notification_messages_token' => $token],
            'message'   => $message,
        ]);
    }

    /**
     * Send a receipt (order) template. $receipt supplies recipient_name,
     * order_number, currency, payment_method, summary{...} and optional
     * elements/address/adjustments. Must be within 24h or under an eligible tag.
     *
     * @return array{ok:bool, mid?:string, error?:string}
     */
    public function sendReceiptTemplate(string $psid, array $receipt): array
    {
        return $this->messagesSend([
            'recipient'      => ['id' => $psid],
            'messaging_type' => 'RESPONSE',
            'message'        => ['attachment' => ['type' => 'template', 'payload' => ['template_type' => 'receipt'] + $receipt]],
        ]);
    }

    /**
     * Read a single message node by mid. CAVEAT: read-by-bare-mid is unreliable —
     * prefer getConversationMessages / webhook backfill. from/to come back as
     * {data:[{id,name,email?}]}. Returns decoded array, [] on failure.
     */
    public function getMessage(string $mid, ?string $fields = null): array
    {
        try {
            $r = self::graph($this->token())->acceptJson()->timeout(15)
                ->get("{$this->base}/{$mid}", [
                    'fields' => $fields ?: 'id,created_time,from,to,message,attachments,sticker,shares,tags',
                ]);

            return $r->successful() ? (array) $r->json() : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Read full message content for a conversation (the reliable path a webhook
     * omitted, since read-by-mid is unreliable). Page-scoped; newest first.
     *
     * @return array{ok:bool, data:array, paging?:mixed, error?:string}
     */
    public function getConversationMessages(string $conversationId, int $limit = 25, ?string $fields = null): array
    {
        try {
            $r = self::graph($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$conversationId}/messages", [
                    'fields' => $fields ?: 'id,created_time,from,to,message,attachments,sticker,shares',
                    'limit'  => $limit,
                ]);
            if ($r->successful()) {
                return ['ok' => true, 'data' => (array) $r->json('data', []), 'paging' => $r->json('paging')];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'read failed');

            return [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    // ── Handover Protocol ──────────────────────────────────────────────────

    /**
     * Shared Handover-Protocol POST (JSON body, Page token). Control transfers are
     * EXEMPT from the 24h window and need no tag; flags token-190 via noteGraphError.
     *
     * @return array{ok:bool, error?:string}
     */
    private function handoverPost(string $edge, array $body): array
    {
        try {
            $r = self::graph($this->token())->acceptJson()->timeout(15)
                ->post("{$this->base}/{$this->page->page_id}/{$edge}", $body);
            if ($r->successful()) {
                return ['ok' => true];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'handover failed');
            $this->noteGraphError($r->json('error'));

            return ['ok' => false, 'error' => $this->lastError];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Pass thread control to another app (default 263902037430900 = Page Inbox /
     * human agent). Caller must currently own the thread (Primary Receiver) or #2018105.
     * Exempt from the 24h window; requires the messaging_handovers webhook to receive events.
     *
     * @return array{ok:bool, error?:string}
     */
    public function passThreadControl(string $psid, int|string $targetAppId = 263902037430900, string $metadata = ''): array
    {
        $body = ['recipient' => ['id' => $psid], 'target_app_id' => $targetAppId];
        if ($metadata !== '') {
            $body['metadata'] = $metadata;
        }

        return $this->handoverPost('pass_thread_control', $body);
    }

    /**
     * Forcibly take thread control back FROM a secondary receiver. Caller MUST be
     * the Primary Receiver app (else #2018105). Control transfer only — no 24h/tag.
     *
     * @return array{ok:bool, error?:string}
     */
    public function takeThreadControl(string $psid, string $metadata = ''): array
    {
        $body = ['recipient' => ['id' => $psid]];
        if ($metadata !== '') {
            $body['metadata'] = $metadata;
        }

        return $this->handoverPost('take_thread_control', $body);
    }

    /**
     * Secondary receiver asks the Primary Receiver to hand it control. 'success'
     * only means the request webhook was delivered, NOT that control was granted.
     * Exempt from the 24h window.
     *
     * @return array{ok:bool, error?:string}
     */
    public function requestThreadControl(string $psid, string $metadata = ''): array
    {
        $body = ['recipient' => ['id' => $psid]];
        if ($metadata !== '') {
            $body['metadata'] = $metadata;
        }

        return $this->handoverPost('request_thread_control', $body);
    }

    /**
     * List the apps registered as secondary receivers on this Page. MUST be called
     * by the Primary Receiver (non-primary callers get empty/error). Returns
     * decoded data[], [] on failure.
     */
    public function getSecondaryReceivers(): array
    {
        try {
            $r = self::graph($this->token())->acceptJson()->timeout(15)
                ->get("{$this->base}/{$this->page->page_id}/secondary_receivers", ['fields' => 'id,name']);
            if ($r->successful()) {
                return (array) $r->json('data', []);
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'read failed');

            return [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Who currently owns a given user's thread (the guard before auto-replying —
     * skip the bot when a human/Page-Inbox app owns it). Compare app_id to your own
     * vs 263902037430900. Returns ['ok'=>true,'app_id'=>...], [] on failure.
     *
     * @return array{ok:bool, app_id?:string, error?:string}|array{}
     */
    public function getThreadOwner(string $psid): array
    {
        try {
            $r = self::graph($this->token())->acceptJson()->timeout(15)
                ->get("{$this->base}/{$this->page->page_id}/thread_owner", ['recipient' => $psid]);
            if ($r->successful()) {
                return ['ok' => true, 'app_id' => (string) data_get($r->json(), 'data.0.thread_owner.app_id', '')];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'read failed');

            return [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    // ── Messenger Profile ──────────────────────────────────────────────────

    /**
     * Shared messenger_profile setter (JSON body, Page token). Every public setter
     * builds its one-key payload and calls this. Rate limit 10 calls / 10 min / page.
     *
     * @return array{ok:bool, error?:string}
     */
    /**
     * @param string $version Graph version override — used only by the greeting
     *                        retry, where the configured version has dropped the
     *                        field but an older one still accepts it. Empty =
     *                        the client's configured version.
     */
    private function postMessengerProfile(array $payload, string $version = ''): array
    {
        $base = $version !== ''
            ? 'https://graph.facebook.com/' . ltrim($version, '/')
            : $this->base;

        try {
            $r = self::graph($this->token())->acceptJson()->timeout(15)
                ->post("{$base}/{$this->page->page_id}/messenger_profile", $payload);
            if ($r->successful()) {
                return ['ok' => true];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'profile update failed');
            $this->noteGraphError($r->json('error'));

            return ['ok' => false, 'error' => $this->lastError];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Shared messenger_profile deleter — DELETE carries a JSON body {fields:[...]}.
     * Every public deleter calls this with its property name(s).
     *
     * @return array{ok:bool, error?:string}
     */
    /** @param string $version Graph version override — see postMessengerProfile(). */
    private function deleteMessengerProfile(array $fields, string $version = ''): array
    {
        $base = $version !== ''
            ? 'https://graph.facebook.com/' . ltrim($version, '/')
            : $this->base;

        try {
            $r = self::graph($this->token())->acceptJson()->timeout(15)
                ->delete("{$base}/{$this->page->page_id}/messenger_profile", ['fields' => array_values($fields)]);
            if ($r->successful()) {
                return ['ok' => true];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'profile delete failed');
            $this->noteGraphError($r->json('error'));

            return ['ok' => false, 'error' => $this->lastError];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Read messenger_profile properties (fields REQUIRED by Meta). Returns the
     * decoded data[] list of {property:value} objects; unset properties are absent.
     * subject_to_new_eu_privacy_rules is read-only. Returns [] on failure.
     */
    public function getMessengerProfile(array $fields = ['get_started', 'greeting', 'ice_breakers', 'persistent_menu', 'whitelisted_domains', 'account_linking_url', 'commands', 'subject_to_new_eu_privacy_rules']): array
    {
        try {
            $r = self::graph($this->token())->acceptJson()->timeout(15)
                ->get("{$this->base}/{$this->page->page_id}/messenger_profile", ['fields' => implode(',', $fields)]);

            return $r->successful() ? (array) $r->json('data', []) : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /** Set the Get Started button payload (echoed in messaging_postbacks on tap; new threads only). */
    public function setGetStarted(string $payload): array
    {
        return $this->postMessengerProfile(['get_started' => ['payload' => $payload]]);
    }

    /** Remove the Get Started button (usually delete alongside persistent_menu to avoid an orphan menu). */
    public function deleteGetStarted(): array
    {
        return $this->deleteMessengerProfile(['get_started']);
    }

    /**
     * Set the welcome-screen greeting — each item {locale,text}, ≥1 with
     * locale='default'; overwrites all.
     *
     * Meta DROPPED `greeting` from messenger_profile in recent Graph versions.
     * On those, a POST carrying only `greeting` contains no field the endpoint
     * recognises, so it answers:
     *
     *   (#100) Requires one of the params: get_started,persistent_menu,
     *   whitelisted_domains,account_linking_url,home_url,ice_breakers,
     *   platform,description,commands
     *
     * — note `greeting` is absent from its own list of accepted params. That is
     * the tell, and it makes the call fail 100% of the time rather than
     * intermittently. Older Graph versions still accept the field, so retry
     * once against the fallback version before giving up; only if that also
     * fails do we report it as unsupported.
     */
    public function setGreeting(array $greetings): array
    {
        $res = $this->postMessengerProfile(['greeting' => array_values($greetings)]);
        if (($res['ok'] ?? false) || ! $this->greetingUnsupported($res['error'] ?? '')) {
            return $res;
        }

        $fallback = self::greetingFallbackVersion();
        if ($fallback !== '' && $fallback !== self::version()) {
            $retry = $this->postMessengerProfile(
                ['greeting' => array_values($greetings)],
                $fallback
            );
            if ($retry['ok'] ?? false) {
                return $retry;
            }
            $res = $retry;
        }

        // Carry Meta's OWN words through to the operator. This branch used to
        // return only the sentence below, which is an INFERENCE — and Meta's
        // reference still lists `greeting` as a supported property, so the
        // inference is not always right. Hiding the real error made the failure
        // undiagnosable: re-installing or upgrading changed nothing, because
        // the text never depended on the version, only on the error matching a
        // substring. Whatever Facebook actually said (permission, bad token,
        // unsupported surface) now reaches the screen.
        $graph = trim((string) ($res['error'] ?? ''));
        Log::warning('[FB-GREETING] rejected', [
            'page_id'         => $this->page->page_id,
            'graph_version'   => self::version(),
            'fallback_tried'  => $fallback !== '' && $fallback !== self::version() ? $fallback : null,
            'graph_error'     => $graph,
        ]);

        return [
            'ok'    => false,
            'error' => __('Facebook rejected the Messenger welcome greeting. Try Ice breakers instead — they show tappable questions on the same welcome screen.')
                . ($graph !== '' ? ' ' . __('Facebook said:') . ' ' . $graph : ''),
            'unsupported' => true,
            'graph_error' => $res['error'] ?? null,
        ];
    }

    /**
     * Graph version to retry the greeting on when the configured one rejects
     * it. Admin-overridable; empty disables the retry entirely.
     */
    public static function greetingFallbackVersion(): string
    {
        return (string) SystemSetting::get('fb_greeting_graph_version', 'v19.0');
    }

    /**
     * True when a Graph error is "this endpoint does not know the `greeting`
     * field" rather than a real failure. Matched on the accepted-params list
     * Meta echoes back: if it enumerates what it takes and `greeting` is not
     * among them, the field is gone on this version. Deliberately narrow — a
     * bad token or a permission error must NOT be swallowed as "unsupported".
     */
    private function greetingUnsupported(string $error): bool
    {
        if ($error === '' || ! str_contains($error, 'Requires one of the params')) {
            return false;
        }

        return ! preg_match('/\bgreeting\b/i', $error);
    }

    /**
     * Delete the greeting across ALL locales (per-locale delete unsupported).
     * Same version problem as setGreeting() — a version that dropped the field
     * cannot delete it either, so retry on the fallback. Removing something
     * Facebook no longer stores is a no-op, so treat a persistent "unsupported"
     * as success rather than blocking the operator on an error they cannot act on.
     */
    public function deleteGreeting(): array
    {
        $res = $this->deleteMessengerProfile(['greeting']);
        if (($res['ok'] ?? false) || ! $this->greetingUnsupported($res['error'] ?? '')) {
            return $res;
        }

        $fallback = self::greetingFallbackVersion();
        if ($fallback !== '' && $fallback !== self::version()) {
            $retry = $this->deleteMessengerProfile(['greeting'], $fallback);
            if ($retry['ok'] ?? false) {
                return $retry;
            }
        }

        return ['ok' => true];
    }

    /** Set the persistent menu — each menu {locale,call_to_actions,...}, ≥1 with locale='default'; replaces the set. */
    public function setPersistentMenu(array $menus): array
    {
        return $this->postMessengerProfile(['persistent_menu' => array_values($menus)]);
    }

    /** Remove all locale persistent menus. */
    public function deletePersistentMenu(): array
    {
        return $this->deleteMessengerProfile(['persistent_menu']);
    }

    /** Set ice breakers (localized {call_to_actions:[{question,payload}],locale} shape; ≤4/locale; tap → messaging_postbacks). */
    public function setIceBreakers(array $iceBreakers): array
    {
        return $this->postMessengerProfile(['ice_breakers' => array_values($iceBreakers)]);
    }

    /** Delete ALL ice breakers across all locales (per-locale delete unsupported). */
    public function deleteIceBreakers(): array
    {
        return $this->deleteMessengerProfile(['ice_breakers']);
    }

    /** Set the domain allowlist for Messenger Extensions / webviews / chat plugin — ≤50, all https; replaces the list. */
    public function setWhitelistedDomains(array $urls): array
    {
        return $this->postMessengerProfile(['whitelisted_domains' => array_values($urls)]);
    }

    /** Clear the domain allowlist. */
    public function deleteWhitelistedDomains(): array
    {
        return $this->deleteMessengerProfile(['whitelisted_domains']);
    }

    /** Set the account-linking callback URL (https) used by the Log In / Log Out button flow. */
    public function setAccountLinkingUrl(string $url): array
    {
        return $this->postMessengerProfile(['account_linking_url' => $url]);
    }

    /** Remove the account-linking URL. */
    public function deleteAccountLinkingUrl(): array
    {
        return $this->deleteMessengerProfile(['account_linking_url']);
    }

    /** Set discoverable bot commands — nested {locale, commands:[{name,description}]} groups, ≥1 locale='default'. */
    public function setCommands(array $commands): array
    {
        return $this->postMessengerProfile(['commands' => array_values($commands)]);
    }

    /** Remove all commands. */
    public function deleteCommands(): array
    {
        return $this->deleteMessengerProfile(['commands']);
    }

    // ── Stories ────────────────────────────────────────────────────────────

    /**
     * Publish a Page photo Story (auto-expires after 24h). Pre-uploads the image
     * UNPUBLISHED then posts to /photo_stories. Recommended 1080x1920 (9:16).
     *
     * @return array{ok:bool, id?:string, error?:string}
     */
    public function publishPhotoStory(string $imageUrl): array
    {
        $photo = $this->uploadPhotoUnpublished($imageUrl);
        if (empty($photo['ok']) || empty($photo['id'])) {
            return ['ok' => false, 'error' => $this->lastError ?: 'photo upload failed'];
        }

        return $this->postGraph("{$this->page->page_id}/photo_stories", ['photo_id' => (string) $photo['id']], ['post_id', 'id']);
    }

    /**
     * Publish a Page video Story via the 3-phase video_stories API (hosted file_url
     * transfer). Story expires 24h; ~≤60s, 9:16. Polls upload status before FINISH.
     *
     * @return array{ok:bool, id?:string, post_id?:string, error?:string}
     */
    public function publishVideoStory(string $fileUrl, string $description = ''): array
    {
        $pageId = $this->page->page_id;
        $token = $this->token();
        try {
            // 1 · START — reserve a video_id + upload_url.
            $start = self::graph($token)->acceptJson()->timeout(30)->asForm()
                ->post("{$this->base}/{$pageId}/video_stories", ['upload_phase' => 'start']);
            if (! $start->successful() || ! $start->json('video_id')) {
                $this->lastError = (string) ($start->json('error.message') ?? 'video story start failed');

                return ['ok' => false, 'error' => $this->lastError];
            }
            $videoId = (string) $start->json('video_id');
            $uploadUrl = (string) $start->json('upload_url');
            if ($uploadUrl === '') {
                $this->lastError = 'video story start returned no upload_url';

                return ['ok' => false, 'error' => $this->lastError];
            }

            // 2 · UPLOAD — hosted transfer: tell Meta where to pull the file from.
            $up = Http::withHeaders([
                'Authorization' => 'OAuth '.$token,
                'file_url'      => $fileUrl,
            ])->timeout(180)->post($uploadUrl);
            if (! $up->successful()) {
                $this->lastError = 'video story upload failed: '.mb_substr($up->body(), 0, 300);

                return ['ok' => false, 'error' => $this->lastError];
            }

            // 2b · WAIT — hosted file downloads async; poll status before finishing.
            for ($i = 0; $i < 20; $i++) {
                $st = self::graph($token)->acceptJson()->timeout(15)
                    ->get("{$this->base}/{$videoId}", ['fields' => 'status']);
                $phase = (string) data_get($st->json(), 'status.uploading_phase.status', '');
                if ($phase === 'complete') {
                    break;
                }
                if ($phase === 'error') {
                    $this->lastError = 'video story upload processing failed';

                    return ['ok' => false, 'error' => $this->lastError];
                }
                usleep(2_000_000); // 2s; ~40s max
            }

            // 3 · FINISH — publish the story.
            $finish = ['upload_phase' => 'finish', 'video_id' => $videoId];
            if ($description !== '') {
                $finish['description'] = mb_substr($description, 0, 2200);
            }
            $done = self::graph($token)->asForm()->acceptJson()->timeout(30)
                ->post("{$this->base}/{$pageId}/video_stories", $finish);
            if ($done->successful()) {
                return ['ok' => true, 'id' => $videoId, 'post_id' => (string) ($done->json('post_id') ?? ''), 'error' => null];
            }
            $this->lastError = (string) ($done->json('error.message') ?? 'video story finish failed');

            return ['ok' => false, 'error' => $this->lastError];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Page management & reads ────────────────────────────────────────────

    /**
     * Rich feed-post publisher (superset of publish()). $spec may carry message,
     * link, place, tags (only with place), message_tags/call_to_action/targeting/
     * feed_targeting (objects → JSON), attached_media (media_fbids), published +
     * scheduled_publish_time. Publishing edge — no 24h/tag.
     *
     * @return array{ok:bool, id:string, error?:string}
     */
    public function createFeedPost(array $spec): array
    {
        $params = [];
        foreach (['message', 'link', 'place', 'published', 'scheduled_publish_time', 'backdated_time', 'backdated_time_granularity', 'no_story', 'multi_share_optimized', 'tags'] as $k) {
            if (array_key_exists($k, $spec) && $spec[$k] !== null && $spec[$k] !== '') {
                $params[$k] = is_bool($spec[$k]) ? ($spec[$k] ? 'true' : 'false') : $spec[$k];
            }
        }
        foreach (['message_tags', 'call_to_action', 'targeting', 'feed_targeting'] as $k) {
            if (! empty($spec[$k])) {
                $params[$k] = is_string($spec[$k]) ? $spec[$k] : json_encode($spec[$k]);
            }
        }
        foreach (array_values((array) ($spec['attached_media'] ?? [])) as $i => $fbid) {
            $params["attached_media[{$i}]"] = json_encode(['media_fbid' => (string) $fbid]);
        }

        return $this->postGraph("{$this->page->page_id}/feed", $params, ['id']);
    }

    /**
     * Publish a link carousel: $childAttachments = 2–10 cards, each {link,name,
     * description,image_hash|picture,call_to_action}. $options may set message,
     * multi_share_optimized, multi_share_end_card, published, scheduled_publish_time.
     *
     * @return array{ok:bool, id:string, error?:string}
     */
    public function createLinkCarousel(array $childAttachments, string $message = '', array $options = []): array
    {
        $params = ['child_attachments' => json_encode(array_values($childAttachments))];
        if ($message !== '') {
            $params['message'] = $message;
        }
        foreach (['multi_share_optimized', 'multi_share_end_card', 'published'] as $k) {
            if (array_key_exists($k, $options)) {
                $params[$k] = is_bool($options[$k]) ? ($options[$k] ? 'true' : 'false') : $options[$k];
            }
        }
        if (! empty($options['scheduled_publish_time'])) {
            $params['scheduled_publish_time'] = (int) $options['scheduled_publish_time'];
        }

        return $this->postGraph("{$this->page->page_id}/feed", $params, ['id']);
    }

    /**
     * Upload an UNPUBLISHED photo and return its media_fbid (id) for /feed reuse
     * (multi-photo, carousel end-cards, scheduled photo posts). temporary='true'
     * requires published='false'. $options: caption,temporary,no_story,alt_text_custom,...
     *
     * @return array{ok:bool, id:string, error?:string}
     */
    public function uploadPhotoUnpublished(string $url, array $options = []): array
    {
        $params = ['url' => $url, 'published' => 'false'];
        foreach (['caption', 'temporary', 'no_story', 'alt_text_custom', 'backdated_time'] as $k) {
            if (array_key_exists($k, $options) && $options[$k] !== null && $options[$k] !== '') {
                $params[$k] = is_bool($options[$k]) ? ($options[$k] ? 'true' : 'false') : $options[$k];
            }
        }
        foreach (['targeting', 'place'] as $k) {
            if (! empty($options[$k])) {
                $params[$k] = is_string($options[$k]) ? $options[$k] : json_encode($options[$k]);
            }
        }

        return $this->postGraph("{$this->page->page_id}/photos", $params, ['id']);
    }

    /**
     * Edit a post the app created (text/targeting/schedule only — media cannot be
     * swapped). Reschedule an unpublished post via scheduled_publish_time. The id
     * is preserved. $fields: message,is_published,scheduled_publish_time,targeting,...
     *
     * @return array{ok:bool, id:string, error?:string}
     */
    public function updatePost(string $postId, array $fields): array
    {
        $params = [];
        foreach (['message', 'is_published', 'scheduled_publish_time'] as $k) {
            if (array_key_exists($k, $fields)) {
                $params[$k] = is_bool($fields[$k]) ? ($fields[$k] ? 'true' : 'false') : $fields[$k];
            }
        }
        foreach (['targeting', 'feed_targeting', 'tags', 'place'] as $k) {
            if (! empty($fields[$k])) {
                $params[$k] = is_string($fields[$k]) ? $fields[$k] : json_encode($fields[$k]);
            }
        }

        return $this->postGraph($postId, $params, ['id', 'success']);
    }

    /**
     * List this Page's unpublished + scheduled posts. To fire one immediately reuse
     * publishNow(). $params: fields,limit,after,before.
     *
     * @return array{ok:bool, data:array, paging?:mixed, error?:string}|array{}
     */
    public function getScheduledPosts(array $params = []): array
    {
        try {
            $query = ['fields' => $params['fields'] ?? 'id,message,scheduled_publish_time,created_time,is_published,permalink_url,full_picture'];
            foreach (['limit', 'after', 'before'] as $k) {
                if (! empty($params[$k])) {
                    $query[$k] = $params[$k];
                }
            }
            $r = self::graph($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$this->page->page_id}/scheduled_posts", $query);
            if ($r->successful()) {
                return ['ok' => true, 'data' => (array) $r->json('data', []), 'paging' => $r->json('paging')];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'read failed');

            return [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * List comments on a post/object (richer than getComment). $params: fields,
     * order,filter,summary,limit,after,before. Comments by other users need
     * pages_read_user_content. Paginate via paging.next.
     *
     * @return array{ok:bool, data:array, paging?:mixed, summary?:mixed, error?:string}|array{}
     */
    public function getPostComments(string $objectId, array $params = []): array
    {
        try {
            $query = ['fields' => $params['fields'] ?? 'id,message,from{id,name,picture},created_time,parent{id},attachment,like_count,comment_count,message_tags'];
            foreach (['order', 'filter', 'summary', 'limit', 'after', 'before'] as $k) {
                if (! empty($params[$k])) {
                    $query[$k] = $params[$k];
                }
            }
            $r = self::graph($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$objectId}/comments", $query);
            if ($r->successful()) {
                return ['ok' => true, 'data' => (array) $r->json('data', []), 'paging' => $r->json('paging'), 'summary' => $r->json('summary')];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'read failed');

            return [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * List reactions on a post/object (read-only — writing a specific reaction is
     * not supported by Graph). Pass type + summary=total_count per type for counts.
     * Note SORRY (not SAD). $params: type,summary,limit,after,before.
     *
     * @return array{ok:bool, data:array, paging?:mixed, summary?:mixed, error?:string}|array{}
     */
    public function getPostReactions(string $objectId, array $params = []): array
    {
        try {
            $query = [];
            foreach (['type', 'summary', 'limit', 'after', 'before'] as $k) {
                if (! empty($params[$k])) {
                    $query[$k] = $params[$k];
                }
            }
            $r = self::graph($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$objectId}/reactions", $query);
            if ($r->successful()) {
                return ['ok' => true, 'data' => (array) $r->json('data', []), 'paging' => $r->json('paging'), 'summary' => $r->json('summary')];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'read failed');

            return [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Chunked resumable upload of a LOCAL video file (start → transfer chunks →
     * finish). $meta: title,description,scheduled_publish_time. For a public URL,
     * postVideo() is simpler. Add subtitles afterwards via uploadVideoCaptions().
     *
     * @return array{ok:bool, id?:string, error?:string}
     */
    public function uploadVideoResumable(string $filePath, array $meta = []): array
    {
        $pageId = $this->page->page_id;
        $token = $this->token();
        try {
            if (! is_file($filePath) || ! is_readable($filePath)) {
                $this->lastError = 'video file not readable: '.$filePath;

                return ['ok' => false, 'error' => $this->lastError];
            }
            $fileSize = (int) filesize($filePath);

            // 1 · START — declare file size, open an upload session.
            $start = self::graph($token)->acceptJson()->timeout(30)->asForm()
                ->post("{$this->base}/{$pageId}/videos", ['upload_phase' => 'start', 'file_size' => $fileSize]);
            if (! $start->successful() || ! $start->json('upload_session_id')) {
                $this->lastError = (string) ($start->json('error.message') ?? 'resumable start failed');
                $this->noteGraphError($start->json('error'));

                return ['ok' => false, 'error' => $this->lastError];
            }
            $sessionId = (string) $start->json('upload_session_id');
            $videoId = (string) $start->json('video_id');
            $startOffset = (int) $start->json('start_offset', 0);
            $endOffset = (int) $start->json('end_offset', 0);

            // 2 · TRANSFER — stream each chunk until start_offset == end_offset.
            $fh = fopen($filePath, 'rb');
            if ($fh === false) {
                $this->lastError = 'cannot open video file';

                return ['ok' => false, 'error' => $this->lastError];
            }
            try {
                for ($guard = 0; $guard < 100000 && $startOffset < $endOffset; $guard++) {
                    fseek($fh, $startOffset);
                    $chunk = (string) fread($fh, max(1, $endOffset - $startOffset));
                    $t = self::graph($token)->acceptJson()->timeout(180)
                        ->attach('video_file_chunk', $chunk, 'chunk')
                        ->post("{$this->base}/{$pageId}/videos", [
                            'upload_phase'      => 'transfer',
                            'upload_session_id' => $sessionId,
                            'start_offset'      => $startOffset,
                        ]);
                    if (! $t->successful()) {
                        $this->lastError = (string) ($t->json('error.message') ?? 'chunk transfer failed');
                        $this->noteGraphError($t->json('error'));

                        return ['ok' => false, 'error' => $this->lastError];
                    }
                    $startOffset = (int) $t->json('start_offset', $endOffset);
                    $endOffset = (int) $t->json('end_offset', $endOffset);
                }
            } finally {
                fclose($fh);
            }

            // 3 · FINISH — commit + metadata (title/description/schedule).
            $finish = ['upload_phase' => 'finish', 'upload_session_id' => $sessionId];
            if (! empty($meta['title'])) {
                $finish['title'] = $meta['title'];
            }
            if (! empty($meta['description'])) {
                $finish['description'] = $meta['description'];
            }
            if (! empty($meta['scheduled_publish_time'])) {
                $finish['published'] = 'false';
                $finish['scheduled_publish_time'] = (int) $meta['scheduled_publish_time'];
            }
            $done = self::graph($token)->acceptJson()->timeout(60)->asForm()
                ->post("{$this->base}/{$pageId}/videos", $finish);
            if ($done->successful()) {
                return ['ok' => true, 'id' => $videoId, 'error' => null];
            }
            $this->lastError = (string) ($done->json('error.message') ?? 'resumable finish failed');
            $this->noteGraphError($done->json('error'));

            return ['ok' => false, 'error' => $this->lastError];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Attach subtitles (a local SRT file) to an existing Page video — one call per
     * locale. Pairs with postVideo() / uploadVideoResumable().
     *
     * @return array{ok:bool, error?:string}
     */
    public function uploadVideoCaptions(string $videoId, string $srtFilePath, string $defaultLocale = 'en_US'): array
    {
        try {
            if (! is_file($srtFilePath) || ! is_readable($srtFilePath)) {
                $this->lastError = 'captions file not readable: '.$srtFilePath;

                return ['ok' => false, 'error' => $this->lastError];
            }
            $r = self::graph($this->token())->acceptJson()->timeout(60)
                ->attach('captions_file', (string) file_get_contents($srtFilePath), $defaultLocale.'.srt')
                ->post("{$this->base}/{$videoId}/captions", ['default_locale' => $defaultLocale]);
            if ($r->successful()) {
                return ['ok' => true];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'caption upload failed');
            $this->noteGraphError($r->json('error'));

            return ['ok' => false, 'error' => $this->lastError];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Page likes an object (post/comment) AS ITSELF — plain LIKE only (reaction type cannot be chosen). */
    public function likeObject(string $objectId): array
    {
        return $this->postGraph("{$objectId}/likes", [], ['success', 'id']);
    }

    /** Remove the Page's OWN like from an object (cannot remove others'). */
    public function unlikeObject(string $objectId): array
    {
        try {
            $r = self::graph($this->token())->acceptJson()->timeout(15)->delete("{$this->base}/{$objectId}/likes");

            return ['ok' => $r->successful()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * List the Page's albums (type ∈ profile|wall|mobile|normal). $params: fields,
     * limit,after,before. Paginate via paging.next.
     *
     * @return array{ok:bool, data:array, paging?:mixed, error?:string}|array{}
     */
    public function getAlbums(array $params = []): array
    {
        try {
            $query = ['fields' => $params['fields'] ?? 'id,name,count,type,cover_photo,created_time,link,privacy'];
            foreach (['limit', 'after', 'before'] as $k) {
                if (! empty($params[$k])) {
                    $query[$k] = $params[$k];
                }
            }
            $r = self::graph($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$this->page->page_id}/albums", $query);
            if ($r->successful()) {
                return ['ok' => true, 'data' => (array) $r->json('data', []), 'paging' => $r->json('paging')];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'read failed');

            return [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Create an album, then add images via addPhotoToAlbum(). CAVEAT: the v23
     * page/albums reference is ambiguous about create — verify against the live
     * Page. $privacy = {value:'EVERYONE'|...}.
     *
     * @return array{ok:bool, id:string, error?:string}
     */
    public function createAlbum(string $name, string $message = '', ?array $privacy = null): array
    {
        $params = ['name' => $name];
        if ($message !== '') {
            $params['message'] = $message;
        }
        if ($privacy !== null) {
            $params['privacy'] = json_encode($privacy);
        }

        return $this->postGraph("{$this->page->page_id}/albums", $params, ['id']);
    }

    /**
     * Add a photo (public HTTPS url) to a specific album. $options: caption,
     * no_story,published,backdated_time,targeting.
     *
     * @return array{ok:bool, id:string, error?:string}
     */
    public function addPhotoToAlbum(string $albumId, string $url, array $options = []): array
    {
        $params = ['url' => $url];
        foreach (['caption', 'no_story', 'published', 'backdated_time'] as $k) {
            if (array_key_exists($k, $options) && $options[$k] !== null && $options[$k] !== '') {
                $params[$k] = is_bool($options[$k]) ? ($options[$k] ? 'true' : 'false') : $options[$k];
            }
        }
        if (! empty($options['targeting'])) {
            $params['targeting'] = is_string($options['targeting']) ? $options['targeting'] : json_encode($options['targeting']);
        }

        return $this->postGraph("{$albumId}/photos", $params, ['id', 'post_id']);
    }

    /**
     * Rich Page profile/business read (about, phone, emails, website, hours,
     * location, verification_status, fan/followers counts, …). Business fields need
     * a Page role (or Page Public Content Access). Returns decoded array, [] on failure.
     */
    public function getPageDetails(?string $fields = null): array
    {
        try {
            $r = self::graph($this->token())->acceptJson()->timeout(15)
                ->get("{$this->base}/{$this->page->page_id}", [
                    'fields' => $fields ?: 'about,description,general_info,phone,emails,website,hours,location,single_line_address,is_published,verification_status,link,engagement,checkins,were_here_count,category,name,username,fan_count,followers_count,price_range,category_list',
                ]);

            return $r->successful() ? (array) $r->json() : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Write Page metadata fields (about, description, phone, website, emails, hours,
     * location, price_range, …). Array sub-objects (hours/location/emails) are
     * JSON-encoded as Meta requires.
     *
     * @return array{ok:bool, id:string, error?:string}
     */
    public function updatePageSettings(array $fields): array
    {
        $params = [];
        foreach ($fields as $k => $v) {
            if ($v === null) {
                continue;
            }
            $params[$k] = is_array($v) ? json_encode($v) : (is_bool($v) ? ($v ? 'true' : 'false') : $v);
        }

        return $this->postGraph($this->page->page_id, $params, ['success', 'id']);
    }

    /**
     * List Messenger conversations (unified threads). CURSOR paging only (no
     * since/until). $params: platform,folder,user_id,fields,limit,after. snippet =
     * last-message preview; unread_count for badges.
     *
     * @return array{ok:bool, data:array, paging?:mixed, error?:string}|array{}
     */
    public function listConversations(array $params = []): array
    {
        try {
            $query = [
                'platform' => $params['platform'] ?? 'MESSENGER',
                'fields'   => $params['fields'] ?? 'participants,senders,unread_count,message_count,updated_time,snippet,can_reply,link',
            ];
            foreach (['folder', 'user_id', 'limit', 'after'] as $k) {
                if (! empty($params[$k])) {
                    $query[$k] = $params[$k];
                }
            }
            $r = self::graph($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$this->page->page_id}/conversations", $query);
            if ($r->successful()) {
                return ['ok' => true, 'data' => (array) $r->json('data', []), 'paging' => $r->json('paging')];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'read failed');

            return [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Read the Page's reviews/recommendations. Legacy 1–5 star `rating` is
     * deprecated — modern reviews are recommendation_type=positive|negative;
     * review_text may be null. Returns decoded data[], [] on failure.
     */
    public function getRatings(array $params = []): array
    {
        try {
            $query = ['fields' => $params['fields'] ?? 'reviewer{id,name,picture},rating,review_text,recommendation_type,created_time,open_graph_story,has_rating,has_review'];
            foreach (['limit', 'after'] as $k) {
                if (! empty($params[$k])) {
                    $query[$k] = $params[$k];
                }
            }
            $r = self::graph($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$this->page->page_id}/ratings", $query);

            return $r->successful() ? (array) $r->json('data', []) : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Read third-party posts that TAG this Page (distinct from published_posts).
     * tagged_time is the tag time vs the post's created_time. Returns decoded
     * data[], [] on failure. $params: fields,limit,after,since,until.
     */
    public function getTaggedPosts(array $params = []): array
    {
        try {
            $query = ['fields' => $params['fields'] ?? 'id,message,story,from,created_time,permalink_url,full_picture,attachments,tagged_time,likes.summary(true),comments.summary(true)'];
            foreach (['limit', 'after', 'since', 'until'] as $k) {
                if (! empty($params[$k])) {
                    $query[$k] = $params[$k];
                }
            }
            $r = self::graph($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$this->page->page_id}/tagged", $query);

            return $r->successful() ? (array) $r->json('data', []) : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Paginated/richer sibling of recentPosts — Page-authored posts only (excludes
     * visitor posts), with engagement summaries + cursor/time paging.
     *
     * @return array{ok:bool, data:array, paging?:mixed, error?:string}|array{}
     */
    public function getPublishedPosts(array $params = []): array
    {
        try {
            $query = ['fields' => $params['fields'] ?? 'id,message,story,created_time,permalink_url,full_picture,is_published,attachments,shares,likes.summary(true),comments.summary(true),reactions.summary(true)'];
            foreach (['limit', 'after', 'since', 'until'] as $k) {
                if (! empty($params[$k])) {
                    $query[$k] = $params[$k];
                }
            }
            $r = self::graph($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$this->page->page_id}/published_posts", $query);
            if ($r->successful()) {
                return ['ok' => true, 'data' => (array) $r->json('data', []), 'paging' => $r->json('paging')];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'read failed');

            return [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Read the Page feed = Page posts + visitor posts (superset of published_posts;
     * visitor posts need pages_read_user_content). Cursor + time (since/until) paging.
     *
     * @return array{ok:bool, data:array, paging?:mixed, error?:string}|array{}
     */
    public function getFeed(array $params = []): array
    {
        try {
            $query = ['fields' => $params['fields'] ?? 'id,message,story,from,created_time,permalink_url,full_picture,attachments,shares,reactions.summary(true),comments.summary(true),likes.summary(true)'];
            foreach (['limit', 'after', 'before', 'since', 'until'] as $k) {
                if (! empty($params[$k])) {
                    $query[$k] = $params[$k];
                }
            }
            $r = self::graph($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$this->page->page_id}/feed", $query);
            if ($r->successful()) {
                return ['ok' => true, 'data' => (array) $r->json('data', []), 'paging' => $r->json('paging')];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'read failed');

            return [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Enable/disable Meta's built-in Wit.ai NLP on Messenger. $options: model,
     * custom_token (your Wit.ai server token), verbose, n_best. Parsed entities then
     * arrive in the `nlp` object on messaging webhook events.
     *
     * @return array{ok:bool, id:string, error?:string}
     */
    public function setNlpConfig(bool $enabled, array $options = []): array
    {
        $params = ['nlp_enabled' => $enabled ? 'true' : 'false'];
        foreach (['model', 'custom_token', 'n_best'] as $k) {
            if (! empty($options[$k])) {
                $params[$k] = $options[$k];
            }
        }
        if (array_key_exists('verbose', $options)) {
            $params['verbose'] = $options['verbose'] ? 'true' : 'false';
        }

        return $this->postGraph("{$this->page->page_id}/nlp_configs", $params, ['success']);
    }

    /**
     * Block Messenger users from interacting with the Page (by PSID). Graph returns
     * a per-id map; postGraph normalizes to ['ok'=>true] on 2xx.
     *
     * @return array{ok:bool, id:string, error?:string}
     */
    public function blockUser(array $psids): array
    {
        return $this->postGraph("{$this->page->page_id}/blocked", ['psid' => json_encode(array_values($psids))], ['success']);
    }

    /** Unblock previously-blocked Messenger users (by PSID). Mirror of blockUser. */
    public function unblockUser(array $psids): array
    {
        try {
            $r = self::graph($this->token())->acceptJson()->timeout(15)
                ->delete("{$this->base}/{$this->page->page_id}/blocked", ['psid' => json_encode(array_values($psids))]);

            return ['ok' => $r->successful()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** List who is currently blocked on the Page. $params: fields,limit,after. Returns decoded data[], [] on failure. */
    public function getBlockedUsers(array $params = []): array
    {
        try {
            $query = ['fields' => $params['fields'] ?? 'id,name'];
            foreach (['limit', 'after'] as $k) {
                if (! empty($params[$k])) {
                    $query[$k] = $params[$k];
                }
            }
            $r = self::graph($this->token())->acceptJson()->timeout(15)
                ->get("{$this->base}/{$this->page->page_id}/blocked", $query);

            return $r->successful() ? (array) $r->json('data', []) : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Read the Page's CTA button(s). READ-ONLY in v23 — programmatic create/update/
     * delete were removed by Meta. Returns decoded data[] (usually 0 or 1), [] on failure.
     */
    public function getCallToActions(): array
    {
        try {
            $r = self::graph($this->token())->acceptJson()->timeout(15)
                ->get("{$this->base}/{$this->page->page_id}/call_to_actions", [
                    'fields' => 'id,type,status,web_url,android_url,iphone_url,intl_number_with_plus,created_time',
                ]);

            return $r->successful() ? (array) $r->json('data', []) : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Create a Messenger custom label (CRM tag). NOTE the param is page_label_name.
     * Store the returned id for associate/remove/delete.
     *
     * @return array{ok:bool, id:string, error?:string}
     */
    public function createLabel(string $name): array
    {
        return $this->postGraph("{$this->page->page_id}/custom_labels", ['page_label_name' => $name], ['id']);
    }

    /**
     * Apply an existing custom label to a Messenger PSID.
     *
     * @return array{ok:bool, id:string, error?:string}
     */
    public function associateLabel(string $labelId, string $psid): array
    {
        return $this->postGraph("{$labelId}/label", ['user' => $psid], ['success']);
    }

    /** Remove a label from a PSID (does not delete the label itself). */
    public function removeLabel(string $labelId, string $psid): array
    {
        try {
            $r = self::graph($this->token())->acceptJson()->timeout(15)
                ->delete("{$this->base}/{$labelId}/label", ['user' => $psid]);

            return ['ok' => $r->successful()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Permanently delete a custom label node (and its associations). */
    public function deleteLabel(string $labelId): array
    {
        try {
            $r = self::graph($this->token())->acceptJson()->timeout(15)->delete("{$this->base}/{$labelId}");

            return ['ok' => $r->successful()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** List all custom labels on the Page. $params: fields,limit,after. Returns decoded data[], [] on failure. */
    public function getLabels(array $params = []): array
    {
        try {
            $query = ['fields' => $params['fields'] ?? 'page_label_name'];
            foreach (['limit', 'after'] as $k) {
                if (! empty($params[$k])) {
                    $query[$k] = $params[$k];
                }
            }
            $r = self::graph($this->token())->acceptJson()->timeout(15)
                ->get("{$this->base}/{$this->page->page_id}/custom_labels", $query);

            return $r->successful() ? (array) $r->json('data', []) : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /** Reverse lookup — which labels are attached to one Messenger PSID. Returns decoded data[], [] on failure. */
    public function getLabelsForPsid(string $psid): array
    {
        try {
            $r = self::graph($this->token())->acceptJson()->timeout(15)
                ->get("{$this->base}/{$psid}/custom_labels", ['fields' => 'page_label_name']);

            return $r->successful() ? (array) $r->json('data', []) : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Fetch the Page profile picture as JSON (redirect=false → {data:{url,width,
     * height,is_silhouette,cache_key}}). is_silhouette=true means no custom avatar.
     * $type = small|normal|large|square. Returns decoded array, [] on failure.
     */
    public function getPagePicture(string $type = 'large', array $params = []): array
    {
        try {
            $query = ['redirect' => 'false', 'type' => $type];
            foreach (['width', 'height'] as $k) {
                if (! empty($params[$k])) {
                    $query[$k] = $params[$k];
                }
            }
            $r = self::graph($this->token())->acceptJson()->timeout(12)
                ->get("{$this->base}/{$this->page->page_id}/picture", $query);

            return $r->successful() ? (array) $r->json() : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Batch up to 50 Graph calls in one HTTP round-trip (the Page token applies to
     * all). Each element's body is a JSON string — decode individually — and a
     * sub-request can fail (its own `code`) while the outer HTTP is 200.
     *
     * @return array{ok:bool, data?:array, error?:string}
     */
    public function batchGraph(array $requests, bool $includeHeaders = false): array
    {
        try {
            $r = self::graph($this->token())->acceptJson()->timeout(60)->asForm()
                ->post($this->base, [
                    'batch'           => json_encode(array_values($requests)),
                    'include_headers' => $includeHeaders ? 'true' : 'false',
                ]);
            if ($r->successful()) {
                return ['ok' => true, 'data' => (array) $r->json()];
            }
            $this->lastError = (string) ($r->json('error.message') ?? 'batch failed');
            $this->noteGraphError($r->json('error'));

            return ['ok' => false, 'error' => $this->lastError];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Insights (extended) ────────────────────────────────────────────────

    /**
     * Richer Page-insights read (adds since/until, date_preset, metric_type). Some
     * classic metrics were deprecated — validate names against the v23 catalog.
     * $params: period,since,until,date_preset,metric_type. Returns decoded data[].
     */
    public function getInsightsMetrics(array $metrics, array $params = []): array
    {
        try {
            $query = ['metric' => implode(',', $metrics), 'period' => $params['period'] ?? 'day'];
            foreach (['since', 'until', 'date_preset', 'metric_type'] as $k) {
                if (! empty($params[$k])) {
                    $query[$k] = $params[$k];
                }
            }
            $r = self::graph($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$this->page->page_id}/insights", $query);

            return $r->successful() ? (array) $r->json('data', []) : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Per-video insights (total_video_views, complete_views, view_time, …). $videoId
     * must be a video owned by this Page; some metrics are lifetime-only. Returns
     * decoded data[], [] on failure.
     */
    public function getVideoInsights(string $videoId, array $metrics, ?string $period = null): array
    {
        try {
            $query = ['metric' => implode(',', $metrics)];
            if ($period) {
                $query['period'] = $period;
            }
            $r = self::graph($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$videoId}/video_insights", $query);

            return $r->successful() ? (array) $r->json('data', []) : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Richer per-post insights (adds period, date_preset, metric_type=total_value
     * and *_by_type breakdowns). Same /insights edge as postInsights. Returns
     * decoded data[], [] on failure.
     */
    public function getPostInsightsFull(string $postId, array $metrics, array $params = []): array
    {
        try {
            $query = ['metric' => implode(',', $metrics)];
            foreach (['period', 'date_preset', 'metric_type'] as $k) {
                if (! empty($params[$k])) {
                    $query[$k] = $params[$k];
                }
            }
            $r = self::graph($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$postId}/insights", $query);

            return $r->successful() ? (array) $r->json('data', []) : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    // ── Commerce / lead-gen ────────────────────────────────────────────────

    /**
     * List the Page's Instant-Form lead forms. questions[] describes the fields —
     * use to map field_data on leads. Needs leads_retrieval + pages_manage_ads.
     * Returns decoded data[], [] on failure.
     */
    public function getLeadForms(array $params = []): array
    {
        try {
            $query = ['fields' => $params['fields'] ?? 'id,name,status,leads_count,locale,created_time,questions,follow_up_action_url,expired_leads_count,organic_leads_count,page'];
            foreach (['limit', 'after'] as $k) {
                if (! empty($params[$k])) {
                    $query[$k] = $params[$k];
                }
            }
            $r = self::graph($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$this->page->page_id}/leadgen_forms", $query);

            return $r->successful() ? (array) $r->json('data', []) : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Retrieve submitted leads for one form (newest first) — the CRM ingest path.
     * Map field_data[].name to the form's questions[].key. Use $params['filtering']
     * for incremental since-last-sync pulls (Meta auto-deletes leads ~90 days).
     * Returns decoded data[], [] on failure.
     */
    public function getFormLeads(string $formId, array $params = []): array
    {
        try {
            $query = ['fields' => $params['fields'] ?? 'id,created_time,field_data,ad_id,ad_name,adset_id,adset_name,campaign_id,campaign_name,form_id,is_organic,platform,partner_name'];
            foreach (['limit', 'after'] as $k) {
                if (! empty($params[$k])) {
                    $query[$k] = $params[$k];
                }
            }
            if (! empty($params['filtering'])) {
                $query['filtering'] = is_string($params['filtering']) ? $params['filtering'] : json_encode($params['filtering']);
            }
            $r = self::graph($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$formId}/leads", $query);

            return $r->successful() ? (array) $r->json('data', []) : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    /**
     * Fetch ONE lead by its leadgen_id — the second half of the realtime path.
     *
     * Meta's `leadgen` webhook delivers IDs only, never the customer's answers,
     * so this call is what actually retrieves them. Needs leads_retrieval on the
     * token (an Advanced-Access permission), which is why a Page can be
     * subscribed and still return nothing until App Review passes.
     *
     * Returns the decoded lead, or [] on failure with the reason on lastError so
     * the caller can record it against the lead row instead of losing it.
     */
    public function getLead(string $leadgenId, array $params = []): array
    {
        try {
            $r = self::graph($this->token())->acceptJson()->timeout(20)
                ->get("{$this->base}/{$leadgenId}", [
                    'fields' => $params['fields']
                        ?? 'id,created_time,field_data,ad_id,ad_name,adset_id,adset_name,campaign_id,campaign_name,form_id,is_organic,platform',
                ]);

            if (! $r->successful()) {
                $this->lastError = (string) ($r->json('error.message') ?? ('HTTP '.$r->status()));

                return [];
            }

            return (array) $r->json();
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }
}
