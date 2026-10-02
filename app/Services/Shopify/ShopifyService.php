<?php

namespace App\Services\Shopify;

use App\Models\ShopifyIntegration;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin HTTP client for Shopify's Admin REST API + OAuth flow.
 *
 * Admin-level credentials (client_id, client_secret, default scopes,
 * redirect URI) come from `system_settings` so a single admin owner can
 * issue Shopify app credentials and every workspace re-uses them.
 *
 * Per-integration secrets (access_token, webhook_secret) live on the
 * `shopify_integrations` row.
 */
class ShopifyService
{
    /**
     * Current stable REST Admin API version. Shopify ships a new version
     * every quarter; bump this when the next stable rolls out. The
     * existing version stays supported for 12 months after rollover.
     */
    public const API_VERSION = '2026-04';

    /** Single timeout for every HTTP call. */
    private const HTTP_TIMEOUT_SECONDS = 15;

    public const WEBHOOK_TOPICS = [
        'orders/create',
        'orders/updated',
        'orders/paid',
        'orders/fulfilled',
        'orders/cancelled',
        // NOTE: `fulfillments/update` was removed — it is NOT a valid webhook
        // topic in current Shopify API versions (registration returns 422
        // "Invalid topic specified" on every sync). Fulfillment/shipping is
        // covered by `orders/fulfilled` above; the Delivered automation keys off
        // that instead of the old shipment_status hook.
        'refunds/create',
        'products/update',
        'customers/create',
        'customers/update',
        'checkouts/create',
        // Cleanup hook: Shopify calls this once when the merchant
        // uninstalls the app. We use it to wipe the access_token so
        // we don't keep calling a revoked credential.
        'app/uninstalled',
    ];

    // write_script_tags lets us inject the WaDesk chat widget onto the
    // storefront (Online Store) via the ScriptTag API when the merchant toggles
    // it on. Stores connected before this scope was added must reconnect once.
    public const DEFAULT_SCOPES = 'read_products,read_orders,write_orders,read_customers,read_checkouts,read_inventory,read_script_tags,write_script_tags';

    // ---------------------------------------------------------------------
    // Admin settings (from system_settings)
    // ---------------------------------------------------------------------

    /**
     * Workspace bound for credential resolution. Set explicitly for calls with
     * no login session — the webhook + compliance handlers bind the store's
     * workspace (via the integration row) BEFORE verifying its HMAC, so a store
     * connected through its OWN Shopify app is verified with that app's secret.
     * In-session calls (connect, callback) leave it null → the auth workspace
     * resolves it. Per-request state only (a fresh container per HTTP request).
     */
    private ?int $boundWorkspaceId = null;

    public function forWorkspace(?int $workspaceId): static
    {
        $this->boundWorkspaceId = $workspaceId ?: null;
        return $this;
    }

    /** This workspace's OWN Shopify app: bound workspace first, else auth workspace. */
    private function ownApp(): ?array
    {
        $ws = $this->boundWorkspaceId
            ? \App\Models\Workspace::find($this->boundWorkspaceId)
            : (auth()->user()?->currentWorkspace);
        return $ws?->ownShopifyApp();
    }

    // OAuth app credentials — prefer the workspace's OWN app (self-serve BYO),
    // fall back to the platform/admin app configured in system_settings.
    public function clientId(): string     { return $this->ownApp()['id'] ?? (string) SystemSetting::get('shopify_client_id', ''); }
    public function clientSecret(): string { return $this->ownApp()['secret'] ?? (string) SystemSetting::get('shopify_client_secret', ''); }
    public function scopes(): string       { return (string) SystemSetting::get('shopify_scopes', self::DEFAULT_SCOPES); }
    public function redirectUri(): string  { return (string) (SystemSetting::get('shopify_redirect_uri') ?: url('/shopify/oauth/callback')); }
    // Enabled when the admin turned Shopify on globally, OR this workspace
    // brought its own Shopify app (self-serve — no admin approval needed).
    public function isEnabled(): bool      { return $this->ownApp() ? true : (bool) SystemSetting::get('shopify_enabled', false); }

    /**
     * Last Admin-API failure, or null when every call since the last reset
     * succeeded. The list getters used to swallow errors and return [], so a
     * revoked token or a missing scope looked identical to "this store has no
     * orders". The dashboard needs to tell those apart to show a diagnosis.
     *
     * @var array{status:int, resource:string}|null
     */
    private ?array $lastError = null;

    /** @return array{status:int, resource:string}|null */
    public function lastError(): ?array { return $this->lastError; }

    /** Record a failed Admin-API call, then return the empty result. */
    private function fail(string $resource, int $status): array
    {
        $this->lastError = ['status' => $status, 'resource' => $resource];

        return [];
    }

    /**
     * Turn an Admin-API failure into something a merchant can act on.
     * "HTTP 403" in an alert() tells nobody what to do next; each status maps
     * to the cause that actually produces it on Shopify plus the fix.
     *
     * @param  array{status:int, resource:string}  $error
     * @return array{title:string, cause:string, fix:array<int,string>, status:int, resource:string}
     */
    public static function diagnose(array $error): array
    {
        $status   = (int) ($error['status'] ?? 0);
        $resource = (string) ($error['resource'] ?? 'store');

        [$title, $cause, $fix] = match (true) {
            $status === 401 => [
                __('Shopify rejected the access token'),
                __('The saved token is no longer valid. This happens when the app was uninstalled from the store, or the token was revoked in the Shopify admin.'),
                [__('Reinstall the app on the store, then Disconnect and reconnect here to mint a fresh token.')],
            ],
            $status === 403 => [
                __('Shopify refused this request'),
                __('The token is valid but not allowed to read :resource. On Shopify a 403 means a scope the app was never granted, or protected customer data access that has not been approved.', ['resource' => $resource]),
                [
                    __('Open the Settings tab and compare "Scopes granted" with the scopes configured in admin. If they differ, the scope list changed after this store connected — Disconnect and reconnect to re-authorise.'),
                    __('In the Shopify Partner Dashboard open the app → API access → Protected customer data. Orders and customers need this approval; without it Shopify issues the token but refuses the calls.'),
                ],
            ],
            $status === 402 => [
                __('The Shopify store is not on an active plan'),
                __('Shopify freezes Admin API access when a store has no active subscription.'),
                [__('Reactivate the store plan in the Shopify admin, then sync again.')],
            ],
            $status === 404 => [
                __('Shopify could not find this store'),
                __('The saved store domain does not resolve to a live store — usually a renamed store, or a typo in the domain.'),
                [__('Disconnect and reconnect using the current .myshopify.com domain.')],
            ],
            $status === 423 => [
                __('The Shopify store is locked'),
                __('Shopify has locked the store, typically for a billing or compliance hold.'),
                [__('Resolve the hold in the Shopify admin, then sync again.')],
            ],
            $status === 429 => [
                __('Shopify rate limit reached'),
                __('Too many API calls in a short window. This one clears by itself.'),
                [__('Wait a minute and sync again.')],
            ],
            $status >= 500 => [
                __('Shopify is having problems'),
                __('Shopify returned a server error. Nothing is wrong with this connection.'),
                [__('Check the Shopify status page, then sync again shortly.')],
            ],
            $status === 0 => [
                __('Could not reach Shopify'),
                __('The request never completed — a network, DNS or firewall problem between this server and Shopify.'),
                [__('Confirm the server has outbound HTTPS access, then sync again.')],
            ],
            default => [
                __('Shopify returned an unexpected response'),
                __('Shopify answered with HTTP :status when reading :resource.', ['status' => $status, 'resource' => $resource]),
                [__('Try syncing again. If it persists, Disconnect and reconnect the store.')],
            ],
        };

        return compact('title', 'cause', 'fix', 'status', 'resource');
    }

    // ---------------------------------------------------------------------
    // OAuth flow
    // ---------------------------------------------------------------------

    /**
     * Build the OAuth authorize URL Shopify documents:
     *   https://{shop}.myshopify.com/admin/oauth/authorize
     * The Unified Admin variant (admin.shopify.com/store/{handle}/…)
     * silently 404s for some sub-region stores; the per-shop host is
     * the spec-compliant path that always works.
     */
    public function authorizeUrl(string $shop, string $state): string
    {
        $shop = $this->normalizeShop($shop);

        return 'https://' . $shop . '/admin/oauth/authorize?' . http_build_query([
            'client_id'    => $this->clientId(),
            'scope'        => $this->scopes(),
            'redirect_uri' => $this->redirectUri(),
            'state'        => $state,
        ]);
    }

    public function verifyOAuthHmac(array $query): bool
    {
        $hmac = (string) ($query['hmac'] ?? '');
        unset($query['hmac'], $query['signature']);
        ksort($query);
        $expected = hash_hmac('sha256', http_build_query($query), $this->clientSecret());
        return $hmac !== '' && hash_equals($expected, $hmac);
    }

    /** @return array{success:bool, access_token?:string, scope?:string, error?:string} */
    public function exchangeCode(string $shop, string $code): array
    {
        $shop = $this->normalizeShop($shop);
        try {
            $r = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                ->post("https://{$shop}/admin/oauth/access_token", [
                    'client_id'     => $this->clientId(),
                    'client_secret' => $this->clientSecret(),
                    'code'          => $code,
                ]);
            if ($r->successful()) {
                return ['success' => true, 'access_token' => $r->json('access_token'), 'scope' => $r->json('scope', '')];
            }
            return ['success' => false, 'error' => $r->json('error_description') ?: $r->json('error') ?: ('HTTP ' . $r->status())];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ---------------------------------------------------------------------
    // Shop info
    // ---------------------------------------------------------------------

    /** @return array{success:bool, shop?:array, error?:string} */
    public function getShop(string $shop, string $accessToken): array
    {
        try {
            $r = $this->client($shop, $accessToken)->get($this->base($shop) . '/shop.json');
            if ($r->successful()) return ['success' => true, 'shop' => $r->json('shop', [])];
            $this->fail('shop', $r->status());
            return ['success' => false, 'error' => 'HTTP ' . $r->status(), 'status' => $r->status()];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Workspace-wide counts. Each resource has its own /count.json endpoint
     * on the Shopify Admin REST API. `orders/count.json` requires an
     * explicit status filter — the others don't.
     */
    public function getStoreCounts(ShopifyIntegration $integration): array
    {
        $resources = [
            'products'  => [],
            'orders'    => ['status' => 'any'],
            'customers' => [],
        ];
        $counts = [];
        foreach ($resources as $resource => $params) {
            $counts[$resource] = 0;
            try {
                $r = $this->client($integration->store_url, $integration->access_token)
                    ->get($this->base($integration->store_url) . "/{$resource}/count.json", $params);
                if ($r->successful()) {
                    $counts[$resource] = (int) $r->json('count', 0);
                } else {
                    $this->fail($resource, $r->status());
                }
            } catch (\Throwable $e) {
                $this->fail($resource, 0);
            }
        }
        return $counts;
    }

    public function getOrders(ShopifyIntegration $integration, int $limit = 20): array
    {
        try {
            $r = $this->client($integration->store_url, $integration->access_token)
                ->get($this->base($integration->store_url) . '/orders.json', [
                    'limit'  => max(1, min(250, $limit)),
                    'status' => 'any',
                    'fields' => 'id,name,email,phone,created_at,cancelled_at,total_price,currency,financial_status,fulfillment_status,line_items,customer,shipping_address,billing_address,order_number',
                ]);
            return $r->successful() ? $r->json('orders', []) : $this->fail('orders', $r->status());
        } catch (\Throwable $e) {
            return $this->fail('orders', 0);
        }
    }

    public function getProducts(ShopifyIntegration $integration, int $limit = 20): array
    {
        try {
            $r = $this->client($integration->store_url, $integration->access_token)
                ->get($this->base($integration->store_url) . '/products.json', [
                    'limit'  => max(1, min(250, $limit)),
                    'fields' => 'id,title,handle,body_html,tags,vendor,product_type,status,images,image,variants,created_at',
                ]);
            return $r->successful() ? $r->json('products', []) : $this->fail('products', $r->status());
        } catch (\Throwable $e) {
            return $this->fail('products', 0);
        }
    }

    public function getCustomers(ShopifyIntegration $integration, int $limit = 20): array
    {
        try {
            $r = $this->client($integration->store_url, $integration->access_token)
                ->get($this->base($integration->store_url) . '/customers.json', [
                    'limit'  => max(1, min(250, $limit)),
                    'fields' => 'id,first_name,last_name,email,phone,orders_count,total_spent,created_at',
                ]);
            return $r->successful() ? $r->json('customers', []) : $this->fail('customers', $r->status());
        } catch (\Throwable $e) {
            return $this->fail('customers', 0);
        }
    }

    // ---------------------------------------------------------------------
    // Webhooks
    // ---------------------------------------------------------------------

    public function registerWebhooks(ShopifyIntegration $integration): array
    {
        $registered = [];
        $address    = url('/shopify/webhook/' . $integration->webhook_secret);
        $base       = $this->base($integration->store_url);
        $client     = fn () => $this->client($integration->store_url, $integration->access_token);

        // 1) What does Shopify ALREADY have? A bare "count 0" from the old code
        // was ambiguous — it could mean "all already registered" (healthy) OR
        // "all rejected". List them first so the truth is in the log, including
        // the ADDRESS each points at (a stale address from an earlier reconnect
        // is the #1 reason real orders never arrive).
        $existing = [];
        try {
            $list = $client()->get($base . '/webhooks.json', ['limit' => 250]);
            foreach ((array) $list->json('webhooks', []) as $w) {
                $existing[] = [
                    'id'      => (string) ($w['id'] ?? ''),
                    'topic'   => (string) ($w['topic'] ?? ''),
                    'address' => (string) ($w['address'] ?? ''),
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('[SHOPIFY] list webhooks failed', ['error' => $e->getMessage()]);
        }
        Log::info('[SHOPIFY] existing webhooks', [
            'store'  => $integration->store_url,
            'wanted' => $address,
            'count'  => count($existing),
            'hooks'  => array_map(fn ($w) => $w['topic'] . ' → ' . $w['address'], $existing),
        ]);

        $byTopic = [];
        foreach ($existing as $w) { $byTopic[$w['topic']][] = $w; }

        $created = []; $deletedStale = []; $kept = []; $failed = [];

        foreach (self::WEBHOOK_TOPICS as $topic) {
            // Keep a correct one; delete any stale (wrong-address) duplicates so
            // Shopify stops delivering to a dead URL.
            $haveCorrect = null;
            foreach ($byTopic[$topic] ?? [] as $w) {
                if ($w['address'] === $address) {
                    $haveCorrect = $w['id'];
                } elseif ($w['id'] !== '') {
                    try {
                        $client()->delete($base . "/webhooks/{$w['id']}.json");
                        $deletedStale[] = $topic . ':' . $w['address'];
                    } catch (\Throwable $e) {
                        // best-effort
                    }
                }
            }
            if ($haveCorrect) {
                $registered[$topic] = $haveCorrect;
                $kept[] = $topic;
                continue;
            }

            // Register fresh — and log the REAL reason when Shopify rejects it
            // (403 = missing read_orders scope; 422 = validation; etc.).
            try {
                $r = $client()->post($base . '/webhooks.json', [
                    'webhook' => ['topic' => $topic, 'address' => $address, 'format' => 'json'],
                ]);
                $id = (string) ($r->json('webhook.id') ?? '');
                if ($r->successful() && $id !== '') {
                    $registered[$topic] = $id;
                    $created[] = $topic;
                } else {
                    $failed[$topic] = $r->json('errors') ?? ('HTTP ' . $r->status());
                    Log::warning('[SHOPIFY] register webhook rejected', [
                        'topic' => $topic, 'status' => $r->status(), 'errors' => $r->json('errors'),
                    ]);
                }
            } catch (\Throwable $e) {
                $failed[$topic] = $e->getMessage();
                Log::warning('[SHOPIFY] register webhook failed', ['topic' => $topic, 'error' => $e->getMessage()]);
            }
        }

        Log::info('[SHOPIFY] webhook reconcile done', [
            'store'         => $integration->store_url,
            'kept'          => $kept,
            'created'       => $created,
            'deleted_stale' => $deletedStale,
            'failed'        => array_keys($failed),
        ]);

        $meta = $integration->metadata ?? [];
        $meta['webhook_ids'] = $registered;
        $integration->update(['metadata' => $meta]);

        return $registered;
    }

    public function deleteWebhooks(ShopifyIntegration $integration): void
    {
        $ids = $integration->metadata['webhook_ids'] ?? [];
        foreach ($ids as $id) {
            if (!$id) continue;
            try {
                $this->client($integration->store_url, $integration->access_token)
                    ->delete($this->base($integration->store_url) . "/webhooks/{$id}.json");
            } catch (\Throwable $e) {
                // best-effort
            }
        }
        $meta = $integration->metadata ?? [];
        $meta['webhook_ids'] = [];
        $integration->update(['metadata' => $meta]);
    }

    // ---------------------------------------------------------------------
    // ScriptTag — inject the WaDesk chat widget onto the storefront
    // ---------------------------------------------------------------------

    /**
     * Register a storefront ScriptTag (Online Store) that loads the widget's
     * self-injecting embed.js. Returns ['ok'=>true,'id'=>...] or an error with
     * the HTTP status (403 = the store's token lacks write_script_tags → it was
     * connected before that scope; the merchant must reconnect once).
     *
     * @return array{ok:bool, id?:string, status?:int, error?:string}
     */
    public function registerScriptTag(ShopifyIntegration $integration, string $src): array
    {
        try {
            $r = $this->client($integration->store_url, $integration->access_token)
                ->post($this->base($integration->store_url) . '/script_tags.json', [
                    'script_tag' => ['event' => 'onload', 'src' => $src, 'display_scope' => 'online_store'],
                ]);
            if ($r->successful() && $r->json('script_tag.id')) {
                return ['ok' => true, 'id' => (string) $r->json('script_tag.id')];
            }
            $err = $r->json('errors');
            return ['ok' => false, 'status' => $r->status(), 'error' => is_string($err) ? $err : json_encode($err ?: ('HTTP ' . $r->status()))];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Remove a storefront ScriptTag by id. Best-effort. */
    public function deleteScriptTag(ShopifyIntegration $integration, string $id): bool
    {
        if (trim($id) === '') return true;
        try {
            $r = $this->client($integration->store_url, $integration->access_token)
                ->delete($this->base($integration->store_url) . "/script_tags/{$id}.json");
            return $r->successful() || $r->status() === 404; // 404 = already gone
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function verifyWebhookSignature(string $payload, string $headerHmac): bool
    {
        if ($headerHmac === '') return false;

        // A webhook is signed with the CLIENT SECRET of whichever app registered
        // it. After a connection/app/domain change (e.g. .in → .com) the
        // workspace's "current" secret can differ from the one that signed the
        // hook — and a real Shopify order that fails HMAC gets a 401, which after
        // ~19 retries makes Shopify DELETE the subscription. So try BOTH the
        // workspace's own-app secret and the platform secret before rejecting.
        foreach ($this->webhookSecretCandidates() as $secret) {
            $expected = base64_encode(hash_hmac('sha256', $payload, $secret, true));
            if (hash_equals($expected, $headerHmac)) return true;
        }
        return false;
    }

    /** Distinct non-empty client secrets a Shopify webhook could be signed with. */
    private function webhookSecretCandidates(): array
    {
        return array_values(array_unique(array_filter([
            $this->ownApp()['secret'] ?? null,
            (string) SystemSetting::get('shopify_client_secret', ''),
        ], fn ($s) => (string) $s !== '')));
    }

    /** Whether ANY client secret is configured to verify webhooks with. */
    public function hasWebhookSecret(): bool
    {
        return count($this->webhookSecretCandidates()) > 0;
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function client(string $shop, string $accessToken)
    {
        return Http::withHeaders(['X-Shopify-Access-Token' => $accessToken])
            ->timeout(self::HTTP_TIMEOUT_SECONDS)
            ->acceptJson();
    }

    private function base(string $shop): string
    {
        return 'https://' . $this->normalizeShop($shop) . '/admin/api/' . self::API_VERSION;
    }

    public function normalizeShop(string $shop): string
    {
        // `i` flag so HTTPS:// / Http:// also strip cleanly.
        $shop = preg_replace('#^https?://#i', '', trim($shop));
        $shop = rtrim($shop, '/');
        if (str_contains($shop, '/')) $shop = explode('/', $shop, 2)[0];
        $shop = strtolower($shop);
        // The UI accepts JUST the store handle (the field shows a ".myshopify.com"
        // suffix and says "just the store handle"). Append the domain so a bare
        // handle like "not-just-vanilla-web" validates instead of erroring until
        // the user pastes the full URL. A handle has no dot; a full domain does.
        if ($shop !== '' && !str_contains($shop, '.')) {
            $shop .= '.myshopify.com';
        }
        return $shop;
    }

    public function isValidShop(string $shop): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9\-]*\.myshopify\.com$/i', $this->normalizeShop($shop));
    }
}
