<?php

namespace App\Services\OpenAiAds;

use App\Models\WaProviderConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * OpenAI (ChatGPT) Ads — Advertiser API client.
 *
 * Multi-tenant: each workspace uses its OWN Ads API key (one key == one ad
 * account), stored per-workspace on a WaProviderConfig row (provider=openai_ads),
 * encrypted at rest — mirrors how the Meta Ads module stores credentials.
 *
 * API facts (verified against developers.openai.com/ads, 2026-09-23):
 *   - Base:  https://api.ads.openai.com/v1
 *   - Auth:  Authorization: Bearer <key>   (static key, no OAuth/refresh)
 *   - Money: micros — 1 unit = 1e-6 of currency ($250 => 250000000).
 *            Every *_micros field is an integer.
 *   - Idempotency-Key header on every CREATE call to de-dupe retries.
 *   - Rate limits: 600/min per endpoint, 1200/min per ad account.
 *
 * Every method returns a normalized envelope:
 *   ['ok' => bool, 'status' => int, 'data' => array, 'error' => ?string]
 * so callers never touch raw HTTP.
 */
class OpenAiAdsClient
{
    public const BASE = 'https://api.ads.openai.com/v1';
    public const PROVIDER = 'openai_ads';

    private const TIMEOUT = 45;

    /** Per-attempt id, prefixes every log line so one call can be grepped. */
    private string $rid = '';

    public function __construct(private readonly string $apiKey) {}

    /**
     * Build a client for a workspace from its stored key. Returns null when the
     * workspace has not connected OpenAI Ads yet (caller shows the connect UI).
     */
    public static function forWorkspace(?int $workspaceId): ?self
    {
        if (! $workspaceId) return null;
        $row = self::configFor($workspaceId);
        $key = trim((string) ($row?->creds()['api_key'] ?? ''));
        return $key !== '' ? new self($key) : null;
    }

    /** The WaProviderConfig row holding this workspace's OpenAI Ads key (or null). */
    public static function configFor(?int $workspaceId): ?WaProviderConfig
    {
        if (! $workspaceId) return null;
        return WaProviderConfig::query()->forWorkspace($workspaceId)
            ->where('provider', self::PROVIDER)->first();
    }

    // ── Money helpers ────────────────────────────────────────────────

    /** Currency amount (major units) → integer micros. 250 => 250000000. */
    public static function toMicros(float|int|string $amount): int
    {
        return (int) round(((float) $amount) * 1_000_000);
    }

    /** Integer micros → currency amount (major units). 250000000 => 250.0. */
    public static function fromMicros(int|string|null $micros): float
    {
        return ((int) $micros) / 1_000_000;
    }

    // ── Ad account ───────────────────────────────────────────────────

    /** GET /ad_account — verify access + read currency/timezone/review status. */
    public function getAdAccount(): array
    {
        return $this->req('GET', '/ad_account');
    }

    /** POST /ad_account/brand — {name?, favicon_file_id?} (>=1 required). */
    public function setBrand(array $body): array
    {
        return $this->req('POST', '/ad_account/brand', $body);
    }

    // ── Files / creatives ────────────────────────────────────────────

    /** POST /upload — {image_url, purpose} (min 640x640). Returns a file id. */
    public function upload(string $imageUrl, string $purpose = 'ad_creative'): array
    {
        return $this->req('POST', '/upload', ['image_url' => $imageUrl, 'purpose' => $purpose], idempotent: true);
    }

    // ── Conversions ──────────────────────────────────────────────────

    /** POST /conversions/pixels — {name, client_type:'web'} → {id(clidsrc_), pixel_id}. */
    public function createPixel(string $name, string $clientType = 'web'): array
    {
        return $this->req('POST', '/conversions/pixels', ['name' => $name, 'client_type' => $clientType], idempotent: true);
    }

    /** POST /conversions/api_keys — {name} → {api_key} (store securely, shown once). */
    public function createConversionsApiKey(string $name): array
    {
        return $this->req('POST', '/conversions/api_keys', ['name' => $name], idempotent: true);
    }

    /**
     * POST /conversions/event_settings — a conversion definition.
     * {name, event_type, custom_event_name?, attribution_window_days, source_ids[1]}
     * → {id(ces_)}.
     */
    public function createEventSetting(array $body): array
    {
        $body += ['attribution_window_days' => 30];
        return $this->req('POST', '/conversions/event_settings', $body, idempotent: true);
    }

    // ── Campaigns ────────────────────────────────────────────────────

    /**
     * POST /campaigns. Required: name, status(active|paused),
     * budget.lifetime_spend_limit_micros (>=1000000). Optional: description,
     * start_time/end_time (unix), bidding_type(impressions|clicks|conversions),
     * conversion_event_setting_ids[], targeting{}. Response id prefix cmpn_.
     */
    public function createCampaign(array $body): array
    {
        return $this->req('POST', '/campaigns', $body, idempotent: true);
    }

    public function listCampaigns(array $query = []): array
    {
        return $this->req('GET', '/campaigns', query: $query);
    }

    public function getCampaign(string $id): array
    {
        return $this->req('GET', '/campaigns/' . $id);
    }

    public function updateCampaign(string $id, array $body): array
    {
        return $this->req('POST', '/campaigns/' . $id, $body);
    }

    public function activateCampaign(string $id): array
    {
        return $this->req('POST', '/campaigns/' . $id . '/activate');
    }

    public function pauseCampaign(string $id): array
    {
        return $this->req('POST', '/campaigns/' . $id . '/pause');
    }

    public function archiveCampaign(string $id): array
    {
        return $this->req('POST', '/campaigns/' . $id . '/archive');
    }

    // ── Ad groups ────────────────────────────────────────────────────

    /**
     * POST /ad_groups. Required: campaign_id, name, status(active|paused),
     * bidding_config{billing_event_type(impression|click), max_bid_micros(>=1)}.
     * Response id prefix adgrp_.
     */
    public function createAdGroup(array $body): array
    {
        return $this->req('POST', '/ad_groups', $body, idempotent: true);
    }

    public function activateAdGroup(string $id): array
    {
        return $this->req('POST', '/ad_groups/' . $id . '/activate');
    }

    public function pauseAdGroup(string $id): array
    {
        return $this->req('POST', '/ad_groups/' . $id . '/pause');
    }

    // ── Ads ──────────────────────────────────────────────────────────

    /**
     * POST /ads. Required: ad_group_id, name, status, creative{type(chat_card|
     * product_ad_template), title(3-50), body(<=100), price?, target_url +
     * file_id required for chat_card}. Response id prefix ad_, review_status.
     */
    public function createAd(array $body): array
    {
        return $this->req('POST', '/ads', $body, idempotent: true);
    }

    public function previewAd(string $id): array
    {
        return $this->req('POST', '/ads/' . $id . '/preview');
    }

    public function activateAd(string $id): array
    {
        return $this->req('POST', '/ads/' . $id . '/activate');
    }

    public function pauseAd(string $id): array
    {
        return $this->req('POST', '/ads/' . $id . '/pause');
    }

    // ── Insights ─────────────────────────────────────────────────────

    /**
     * Delivery metrics. $scope is one of: 'ad_account' (→ /ad_account/insights)
     * or 'campaigns'|'ad_groups'|'ads' with an $id (→ /<scope>/{id}/insights).
     * $query: time_granularity, aggregation_level, time_ranges[], fields[],
     * filters[], sort[], segments[] (one max), limit(1-2000), before/after.
     */
    public function insights(string $scope, ?string $id = null, array $query = []): array
    {
        $path = $scope === 'ad_account'
            ? '/ad_account/insights'
            : '/' . $scope . '/' . $id . '/insights';
        return $this->req('GET', $path, query: $query);
    }

    /** POST /conversions/insights — conversion counts (click/view-through). */
    public function conversionsInsights(array $body): array
    {
        return $this->req('POST', '/conversions/insights', $body);
    }

    // ── Core HTTP ────────────────────────────────────────────────────

    /**
     * @return array{ok:bool,status:int,data:array,error:?string}
     */
    private function req(string $method, string $path, array $body = [], array $query = [], bool $idempotent = false): array
    {
        $this->rid = (string) Str::random(8);

        try {
            $req = Http::timeout(self::TIMEOUT)
                ->withToken($this->apiKey)
                ->acceptJson();

            // De-dupe creates — a retried POST with the same key is a no-op server-side.
            if ($idempotent) {
                $req = $req->withHeaders(['Idempotency-Key' => (string) Str::uuid()]);
            }

            $url = self::BASE . $path;
            $r = match (strtoupper($method)) {
                'GET'  => $req->get($url, $query),
                default => $req->asJson()->post($url, $body),
            };

            $data = $r->json() ?: [];

            if ($r->successful()) {
                return ['ok' => true, 'status' => $r->status(), 'data' => $data, 'error' => null];
            }

            // Surface the API's own error message (Bearer errors, validation, 403
            // "feature not enabled", 429 rate-limit) rather than a bare status.
            $error = (string) (
                data_get($data, 'error.message')
                ?? data_get($data, 'error')
                ?? data_get($data, 'message')
                ?? ('http_' . $r->status())
            );
            $this->log('request_failed', ['method' => $method, 'path' => $path, 'status' => $r->status(), 'error' => $error], 'warning');

            return ['ok' => false, 'status' => $r->status(), 'data' => $data, 'error' => $error];
        } catch (\Throwable $e) {
            $this->log('exception', ['method' => $method, 'path' => $path, 'error' => $e->getMessage()], 'error');
            return ['ok' => false, 'status' => 0, 'data' => [], 'error' => $e->getMessage()];
        }
    }

    private function log(string $step, array $ctx = [], string $level = 'info'): void
    {
        $msg = "OpenAiAds[{$this->rid}] {$step}";
        $ctx = ['rid' => $this->rid] + $ctx;
        match ($level) {
            'error'   => Log::error($msg, $ctx),
            'warning' => Log::warning($msg, $ctx),
            default   => Log::info($msg, $ctx),
        };
    }
}
