<?php

namespace App\Http\Controllers;

use App\Models\ShopifyIntegration;
use App\Models\ShopifyIntegrationEvent;
use App\Models\ShopifyIntegrationLog;
use App\Models\SystemSetting;
use App\Models\WaTemplate;
use App\Services\Shopify\ShopifyService;
use App\Support\ChannelSetupReturn;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ShopifyController extends Controller
{
    public function __construct(private readonly ShopifyService $shopify) {}

    /**
     * GET /shopify — single page with tabs. If not connected, shows the
     * install/connect form. If connected, shows the dashboard.
     */
    public function index(Request $request): View
    {
        $user = Auth::user();
        $wsId = $user?->current_workspace_id;
        $integration = $wsId
            ? ShopifyIntegration::where('workspace_id', $wsId)->latest('id')->first()
            : null;

        $activeTab = $request->string('tab')->toString() ?: 'overview';
        // Ready to connect when EITHER the admin configured a global Shopify app
        // OR this workspace brought its OWN app (self-serve, no admin needed).
        $appEnabled = $this->shopify->isEnabled() && $this->shopify->clientId() !== '';

        $workspace = $wsId ? \App\Models\Workspace::find($wsId) : null;

        $viewData = [
            'integration'       => $integration,
            'activeTab'         => $activeTab,
            'appEnabled'        => $appEnabled,
            'eventTopics'       => ShopifyService::WEBHOOK_TOPICS,
            // Self-serve BYO Shopify app (owner-only, gated by admin toggle).
            'shopifyOwnApp'      => $workspace?->ownShopifyApp(),
            'shopifyIsOwner'     => $workspace && (int) $workspace->owner_user_id === (int) ($user?->id ?? 0),
            'shopifyManualAllowed'=> (bool) SystemSetting::get('shopify_allow_manual_app', false),
            'shopifyRedirectUri' => $this->shopify->redirectUri(),
        ];

        if ($integration && $integration->isConnected()) {
            $viewData = array_merge($viewData, $this->dashboardData($integration));
        }

        return view('user.shopify.dashboard', $viewData);
    }

    /**
     * POST /shopify/own-app — save this workspace's OWN Shopify app (API key +
     * secret) so it connects Shopify through its own app, no admin config or
     * approval. Owner-only. Blank both = clear (back to the platform/admin app).
     * Blank secret with a key present keeps the stored secret.
     */
    public function saveOwnApp(Request $request)
    {
        $user = Auth::user();
        $ws   = $user?->currentWorkspace;
        if (! $ws) abort(403);
        if (! (bool) SystemSetting::get('shopify_allow_manual_app', false)) {
            abort(403, __('Connecting Shopify with your own app is not enabled.'));
        }
        if ((int) $ws->owner_user_id !== (int) $user->id) {
            abort(403, __('Only the workspace owner can change this.'));
        }

        $data = $request->validate([
            'shopify_client_id'     => ['nullable', 'string', 'max:191'],
            'shopify_client_secret' => ['nullable', 'string', 'max:191'],
        ]);

        $ws->shopify_client_id = trim((string) ($data['shopify_client_id'] ?? '')) ?: null;
        $secret = trim((string) ($data['shopify_client_secret'] ?? ''));
        if ($secret !== '') {
            $ws->shopify_client_secret = $secret;         // encrypted cast
        } elseif ($ws->shopify_client_id === null) {
            $ws->shopify_client_secret = null;            // fully cleared → back to admin app
        }
        $ws->save();

        return back()->with('success', $ws->fresh()->ownShopifyApp()
            ? __('Your Shopify app is saved. Now enter your store domain and connect.')
            : __('Your Shopify app keys were cleared. Shopify will use the platform app if the admin has configured one.'));
    }

    /**
     * POST /shopify/{id}/widget — toggle the WaDesk chat widget on the store.
     *
     * ON  → register a storefront ScriptTag pointing at the selected widget's
     *       self-injecting embed.js, so the launcher shows on every storefront
     *       page and visitor chats land in the team inbox (AI can reply) exactly
     *       like the normal widget.
     * OFF → remove the ScriptTag.
     * The ScriptTag id + state live on the integration's metadata.
     */
    public function saveWidget(Request $request, int $id): RedirectResponse
    {
        $wsId = Auth::user()?->current_workspace_id;
        $integration = ShopifyIntegration::where('workspace_id', $wsId)->findOrFail($id);

        $enable   = $request->boolean('widget_enabled');
        $widgetId = (int) $request->input('widget_id', 0);
        $meta     = $integration->metadata ?? [];

        // Always clear any existing ScriptTag first (idempotent — a re-enable or
        // a widget change replaces it cleanly).
        $existingTag = (string) ($meta['widget_script_tag_id'] ?? '');
        if ($existingTag !== '') {
            $this->shopify->deleteScriptTag($integration, $existingTag);
            $meta['widget_script_tag_id'] = null;
        }

        if (! $enable) {
            $meta['widget_enabled'] = false;
            $meta['widget_id'] = null;
            $integration->update(['metadata' => $meta]);
            return back()->with('success', __('Chat widget removed from your Shopify store.'));
        }

        $widget = \App\Models\ChatbotWidget::where('workspace_id', $wsId)
            ->where('status', 'active')
            ->when($widgetId > 0, fn ($q) => $q->where('id', $widgetId))
            ->orderBy('id')
            ->first();
        if (! $widget) {
            return back()->withErrors(['widget' => __('Create and activate a chat widget first (Chat Widget in the sidebar), then enable it here.')]);
        }

        $src = url('/widget/' . $widget->embed_token . '/embed.js');
        $res = $this->shopify->registerScriptTag($integration, $src);
        if (empty($res['ok'])) {
            $why = ((int) ($res['status'] ?? 0) === 403 || (int) ($res['status'] ?? 0) === 401)
                ? __('Reconnect your store first — it needs a new permission to place the widget. Disconnect and reconnect, then enable again.')
                : (string) ($res['error'] ?? __('Shopify refused the request.'));
            return back()->withErrors(['widget' => $why]);
        }

        $meta['widget_enabled']       = true;
        $meta['widget_id']            = $widget->id;
        $meta['widget_script_tag_id'] = (string) $res['id'];
        $integration->update(['metadata' => $meta]);

        return back()->with('success', __('Chat widget “:name” is now live on your Shopify store.', ['name' => $widget->name]));
    }

    /**
     * POST /shopify/connect — Validate the shop domain, store CSRF state
     * in session, redirect to Shopify's authorize endpoint.
     */
    public function startOAuth(Request $request)
    {
        $request->validate([
            'shop' => ['required', 'string', 'max:191'],
        ]);

        ChannelSetupReturn::remember();
        $shop = $this->shopify->normalizeShop($request->string('shop')->toString());
        if (!$this->shopify->isValidShop($shop)) {
            return back()->with('error', 'Enter a valid Shopify domain like my-store.myshopify.com.');
        }

        if (!$this->shopify->isEnabled() || $this->shopify->clientId() === '') {
            return back()->with('error', __('Add your Shopify app first — enter your API key and secret in the "Use your own Shopify app" panel, then connect your store.'));
        }

        $state = Str::random(40);
        session(['shopify_oauth_state' => $state, 'shopify_oauth_shop' => $shop]);

        return redirect()->away($this->shopify->authorizeUrl($shop, $state));
    }

    /**
     * GET /shopify/oauth/callback — Verify HMAC + state, exchange code,
     * persist integration, register webhooks.
     */
    public function oauthCallback(Request $request)
    {
        // Plan: integration must be enabled on the workspace's plan.
        \App\Services\PlanLimitGuard::feature($request->user()?->currentWorkspace, 'integration_shopify');

        $query = $request->query();

        if (!$this->shopify->verifyOAuthHmac($query)) {
            return redirect(ChannelSetupReturn::url('/shopify'))->with('error', 'Invalid Shopify signature. Try again.');
        }

        $sessionState = session('shopify_oauth_state');
        $sessionShop  = session('shopify_oauth_shop');
        $state = (string) $request->query('state', '');
        $shop  = (string) $request->query('shop', '');
        $code  = (string) $request->query('code', '');

        if (!$state || !$sessionState || !hash_equals((string) $sessionState, $state)) {
            return redirect(ChannelSetupReturn::url('/shopify'))->with('error', 'Session expired. Please reconnect.');
        }
        if (!$shop || $shop !== $sessionShop) {
            return redirect(ChannelSetupReturn::url('/shopify'))->with('error', 'Shop mismatch during callback.');
        }

        $exchange = $this->shopify->exchangeCode($shop, $code);
        if (!$exchange['success']) {
            return redirect(ChannelSetupReturn::url('/shopify'))->with('error', 'OAuth failed: ' . ($exchange['error'] ?? 'unknown'));
        }

        $user = Auth::user();
        $wsId = $user?->current_workspace_id;
        if (!$wsId) {
            return redirect(ChannelSetupReturn::url('/shopify'))->with('error', 'No workspace selected for this account.');
        }

        $shopData = $this->shopify->getShop($shop, $exchange['access_token'])['shop'] ?? [];

        // If a row already exists for (workspace, shop), tear down the OLD
        // Shopify-side webhook subscriptions first. The new webhook_secret
        // (issued below in updateOrCreate) will route through a different
        // URL — otherwise the old subscriptions deliver to a 404.
        $existing = ShopifyIntegration::where('workspace_id', $wsId)
            ->where('store_url', $shop)
            ->first();
        if ($existing) {
            try { $this->shopify->deleteWebhooks($existing); } catch (\Throwable $e) {}
        }

        $integration = ShopifyIntegration::updateOrCreate(
            ['workspace_id' => $wsId, 'store_url' => $shop],
            [
                'user_id'          => $user->id,
                'store_name'       => $shopData['name'] ?? $shop,
                'shop_id'          => isset($shopData['id']) ? (string) $shopData['id'] : null,
                'shop_email'       => $shopData['email'] ?? null,
                'shop_owner'       => $shopData['shop_owner'] ?? null,
                'shop_plan'        => $shopData['plan_name'] ?? null,
                'shop_currency'    => $shopData['currency'] ?? null,
                'shop_country'     => $shopData['country_name'] ?? null,
                'access_token'     => $exchange['access_token'],
                'scopes'           => $exchange['scope'] ?? '',
                'status'           => 'active',
                'webhook_secret'   => Str::random(40),
                'last_verified_at' => now(),
                'connected_at'     => now(),
            ],
        );

        try {
            $this->shopify->registerWebhooks($integration);
        } catch (\Throwable $e) {
            Log::warning('[SHOPIFY] register webhooks failed', ['error' => $e->getMessage()]);
        }

        // Initial import of products / orders / customers into our tables so
        // the dashboard, catalog send, broadcasts and automations all run on
        // real local data. Best-effort — never block the connect on it.
        try {
            app(\App\Services\Shopify\ShopifyImporter::class)->importAll($integration);
        } catch (\Throwable $e) {
            Log::warning('[SHOPIFY] initial import failed', ['error' => $e->getMessage()]);
        }

        session()->forget(['shopify_oauth_state', 'shopify_oauth_shop']);

        return redirect(ChannelSetupReturn::url('/shopify?tab=overview'))->with('success', 'Shopify store connected.');
    }

    /**
     * POST /shopify/{id}/sync — synchronous AJAX endpoint that re-fetches
     * counts and shop info, updating the integration row.
     */
    public function sync(int $id): JsonResponse
    {
        // Deploy proof — this MUST appear the instant "Sync now" is clicked. If
        // you click Sync and this line is absent from the log, the updated
        // controller is NOT running (PHP OPcache is serving the old file — reload
        // php-fpm, or the file wasn't actually replaced on the server).
        Log::info('[SHOPIFY] sync called', ['id' => $id, 'build' => 'sync-webhook-reregister-v1']);

        $integration = $this->ownedIntegration($id);
        if (!$integration) return response()->json(['ok' => false, 'message' => 'Not found.'], 404);

        $shopResult = $this->shopify->getShop($integration->store_url, $integration->access_token);
        if (!($shopResult['success'] ?? false)) {
            $integration->update(['status' => 'error']);
            return response()->json(['ok' => false, 'message' => $shopResult['error'] ?? 'Verify failed']);
        }

        $shopData = $shopResult['shop'] ?? [];
        $integration->update([
            'store_name'       => $shopData['name'] ?? $integration->store_name,
            'shop_email'       => $shopData['email'] ?? $integration->shop_email,
            'shop_owner'       => $shopData['shop_owner'] ?? $integration->shop_owner,
            'shop_plan'        => $shopData['plan_name'] ?? $integration->shop_plan,
            'shop_currency'    => $shopData['currency'] ?? $integration->shop_currency,
            'shop_country'     => $shopData['country_name'] ?? $integration->shop_country,
            'status'           => 'active',
            'last_verified_at' => now(),
        ]);

        // Re-register webhooks on every Sync. THIS is the fix for "automation
        // never fires on real orders": if the store's webhook subscriptions were
        // never created (connected before registration worked), or point at a
        // stale secret URL after a reconnect, no order webhook ever reaches us —
        // and no [Shopify-webhook] log appears because webhook() is never called.
        // registerWebhooks re-adds the CURRENT-URL subscription for every topic
        // (Shopify ignores an already-registered topic+address), so a Sync now
        // repairs a broken subscription without a full disconnect/reconnect.
        try {
            $hooks = $this->shopify->registerWebhooks($integration);
            Log::info('[SHOPIFY] webhooks ensured on sync', [
                'integration' => $integration->id,
                'store'       => $integration->store_url,
                'registered'  => array_keys($hooks),
                'count'       => count($hooks),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[SHOPIFY] webhook re-register on sync failed', ['error' => $e->getMessage()]);
        }

        // Pull products / orders / customers into our tables. Best-effort —
        // a partial failure still returns the shop refresh result.
        $imported = ['products' => 0, 'orders' => 0, 'customers' => 0];
        try {
            $imported = app(\App\Services\Shopify\ShopifyImporter::class)->importAll($integration);
        } catch (\Throwable $e) {
            Log::warning('[SHOPIFY] sync import failed', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'ok'       => true,
            'imported' => $imported,
            'counts'   => $this->shopify->getStoreCounts($integration),
            'shop'     => [
                'name'    => $integration->store_name,
                'plan'    => $integration->shop_plan,
                'country' => $integration->shop_country,
            ],
        ]);
    }

    /**
     * POST /shopify/{id}/disconnect — delete webhooks then remove the row.
     * No soft-deletes on this model, so this is a hard delete; reconnecting
     * issues a fresh integration row + webhook secret.
     */
    public function disconnect(int $id)
    {
        $integration = $this->ownedIntegration($id);
        if (!$integration) abort(404);

        try { $this->shopify->deleteWebhooks($integration); } catch (\Throwable $e) {}
        $integration->delete();

        return redirect('/shopify')->with('success', 'Shopify disconnected.');
    }

    /**
     * POST /shopify/{id}/events — bulk save event → template mappings.
     */
    public function saveEvents(int $id, Request $request): JsonResponse
    {
        $integration = $this->ownedIntegration($id);
        if (!$integration) return response()->json(['ok' => false], 404);

        $data = $request->validate([
            'events'                  => 'required|array',
            'events.*.is_active'      => 'required|boolean',
            'events.*.template_id'    => 'nullable|integer',
            'events.*.var_map'        => 'nullable|array',
            'events.*.var_map.*'      => 'nullable|string|max:40',
            'events.*.send_to'        => 'nullable|in:customer,admin,both',
            'events.*.admin_number'   => 'nullable|string|max:32',
            'events.*.delay_seconds'  => 'nullable|integer|min:0|max:86400',
        ]);

        DB::transaction(function () use ($integration, $data) {
            $allowedTypes = array_merge(ShopifyService::WEBHOOK_TOPICS, ['cod/confirm', 'cod/prepaid', 'stock/back', 'order/delivered', 'cart/step2', 'cart/step3']);
            foreach ($data['events'] as $type => $row) {
                if (!in_array($type, $allowedTypes, true)) continue;
                // Preserve POSITION — one entry per template placeholder, in
                // order. array_filter() used to drop blank pickers and reindex,
                // which shifted every later field up by one: the value chosen
                // for {{4}} then landed on {{3}} (or vice-versa), so e.g. the
                // Amount placeholder rendered the Order ID. Keep blanks as ''
                // so slot i always maps to placeholder i; store null only when
                // nothing at all was picked.
                $rawMap = array_map(fn ($v) => (string) ($v ?? ''), array_values((array) ($row['var_map'] ?? [])));
                $varMap = array_filter($rawMap, fn ($v) => $v !== '') ? $rawMap : null;
                ShopifyIntegrationEvent::updateOrCreate(
                    ['integration_id' => $integration->id, 'event_type' => $type],
                    [
                        'is_active'     => (bool) ($row['is_active'] ?? false),
                        'template_id'   => $row['template_id'] ?: null,
                        'var_map'       => $varMap ?: null,
                        'send_to'       => $row['send_to'] ?? 'customer',
                        'admin_number'  => $row['admin_number'] ?? null,
                        'delay_seconds' => (int) ($row['delay_seconds'] ?? 0),
                    ],
                );
            }
        });

        return response()->json(['ok' => true]);
    }

    /**
     * POST /shopify/{id}/test-order — fire a chosen automation with a DUMMY
     * order so the merchant can confirm the WhatsApp template really lands on
     * their own phone. It runs the EXACT same path a live Shopify webhook uses
     * (dispatchEventMessage → resolveRecipient E.164 → CommerceEventNotifier),
     * so a pass here proves the real automation works end-to-end. Two things
     * are forced for the test only: the send goes to the tester's number
     * (send_to = customer) and fires immediately (no delay).
     */
    public function testOrder(int $id, Request $request): JsonResponse
    {
        $integration = $this->ownedIntegration($id);
        if (!$integration) return response()->json(['ok' => false, 'error' => 'Not found.'], 404);

        $input = $request->validate([
            'phone'      => 'required|string|max:32',
            'event_type' => 'nullable|string|max:40',
        ]);

        $topic = $input['event_type'] ?: 'orders/create';

        // The automation must be enabled + have a template, exactly like a live
        // webhook — otherwise there is nothing to send. Point the merchant at
        // the switch instead of silently doing nothing.
        $event = ShopifyIntegrationEvent::where('integration_id', $integration->id)
            ->where('event_type', $topic)->first();
        if (!$event || !$event->is_active || !$event->template_id) {
            return response()->json([
                'ok'    => false,
                'error' => 'Turn this automation on and pick a template first, then send a test.',
            ], 422);
        }

        // A Shopify-shaped dummy order carrying the tester's number as the
        // customer phone. resolveRecipient() will normalise it to E.164 (adding
        // the dialing code) — the very step that makes real orders deliver.
        $storeUrl = (string) ($integration->store_url ?? '');
        $data = [
            'id'                 => 'TEST-' . now()->timestamp,
            'name'               => '#TEST' . random_int(1000, 9999),
            'order_number'       => random_int(1000, 9999),
            'currency'           => $integration->shop_currency ?: 'INR',
            'total_price'        => '199.00',
            'financial_status'   => 'paid',
            'fulfillment_status' => null,
            'email'              => 'test@example.com',
            'customer'           => [
                'first_name' => 'Test',
                'last_name'  => 'Customer',
                'phone'      => $input['phone'],
                'email'      => 'test@example.com',
            ],
            'phone'              => $input['phone'],
            'order_status_url'   => ($storeUrl ? rtrim($storeUrl, '/') : '') . '/account/orders',
        ];

        $recipient = $this->resolveRecipient($data, $this->storeCountryIso($integration));

        $log = ShopifyIntegrationLog::create([
            'integration_id' => $integration->id,
            'event_type'     => 'test/' . $topic,
            'status'         => 'processed',
            'recipient'      => $recipient,
            'payload'        => $data,
            'created_at'     => now(),
        ]);

        // Same send method the real webhook uses — only send_to (to the tester)
        // and delay (immediate) are overridden on a throwaway copy of the event.
        $testEvent = $event->replicate();
        $testEvent->send_to = 'customer';
        $testEvent->delay_seconds = 0;

        try {
            $this->dispatchEventMessage($integration, $testEvent, $data, $log);
        } catch (\Throwable $e) {
            Log::warning('[Shopify-test-order] dispatch crashed: ' . $e->getMessage());
            $log->update(['status' => 'failed', 'error' => $e->getMessage()]);
        }

        $log->refresh();
        $ok = in_array($log->status, ['sent', 'scheduled'], true);

        return response()->json([
            'ok'        => $ok,
            'status'    => $log->status,
            'recipient' => $recipient,
            'error'     => $log->error,
        ]);
    }

    /**
     * POST /shopify/{id}/offer — send an approved template (a product offer
     * / promo) to every contact in a chosen segment, engine-aware. Reuses
     * CommerceEventNotifier; injects product + coupon into the variable
     * context. Returns sent/failed counts. Logged as an `offer/broadcast`.
     */
    public function sendOffer(int $id, Request $request): JsonResponse
    {
        $integration = $this->ownedIntegration($id);
        if (!$integration) return response()->json(['ok' => false, 'message' => 'Not found.'], 404);

        // Plan gate — same feature flag the connect flow enforces.
        try {
            \App\Services\PlanLimitGuard::feature($request->user()?->currentWorkspace, 'integration_shopify');
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Your plan does not include the Shopify integration.'], 403);
        }

        $data = $request->validate([
            'template_id'   => 'required|integer',
            'group_id'      => 'required|string|max:64',
            'product_ids'   => 'nullable|array',
            'product_ids.*' => 'integer',
            'coupon_code'   => 'nullable|string|max:64',
        ]);

        $tpl = WaTemplate::where('workspace_id', $integration->workspace_id)->find($data['template_id']);
        if (!$tpl) return response()->json(['ok' => false, 'message' => 'Template not found.']);

        $gid = (string) $data['group_id'];
        $contacts = \App\Models\Contact::where('workspace_id', $integration->workspace_id)
            ->where('is_unsubscribed', false)
            ->get()
            ->filter(function ($c) use ($gid) {
                $groups = is_array($c->contact_group) ? array_map('strval', $c->contact_group) : [];
                return in_array($gid, $groups, true);
            });

        if ($contacts->isEmpty()) {
            return response()->json(['ok' => false, 'message' => 'That segment has no contactable (opted-in) members.']);
        }

        $product = !empty($data['product_ids'])
            ? \App\Models\WaProduct::where('workspace_id', $integration->workspace_id)->find($data['product_ids'][0])
            : null;

        $notifier = app(\App\Services\Commerce\CommerceEventNotifier::class);
        $sent = 0; $fail = 0;
        foreach ($contacts as $c) {
            $phone = preg_replace('/\D+/', '', (string) $c->mobile);
            if ($phone === '') { $fail++; continue; }
            $ctx = $this->offerContext($integration, $product, $data['coupon_code'] ?? null, $c);
            $r = $notifier->notify($integration->workspace_id, $integration->user_id, $phone, $tpl, $ctx);
            ($r['ok'] ?? false) ? $sent++ : $fail++;
        }

        ShopifyIntegrationLog::create([
            'integration_id' => $integration->id,
            'event_type'     => 'offer/broadcast',
            'status'         => $sent > 0 ? 'sent' : 'failed',
            'recipient'      => $contacts->count() . ' contacts',
            'payload'        => ['product' => $product?->name, 'coupon' => $data['coupon_code'] ?? null, 'template' => $tpl->template_name],
            'response'       => ['sent' => $sent, 'failed' => $fail],
            'created_at'     => now(),
        ]);

        return response()->json(['ok' => true, 'sent' => $sent, 'failed' => $fail, 'total' => $contacts->count()]);
    }

    /**
     * POST /shopify/{id}/winback — re-engage lapsed customers: everyone
     * whose most-recent order is older than N days. One-click (no cron):
     * the merchant runs it on demand. Engine-aware, coupon-aware, skips
     * opted-out contacts.
     */
    public function sendWinback(int $id, Request $request): JsonResponse
    {
        $integration = $this->ownedIntegration($id);
        if (!$integration) return response()->json(['ok' => false, 'message' => 'Not found.'], 404);
        try {
            \App\Services\PlanLimitGuard::feature($request->user()?->currentWorkspace, 'integration_shopify');
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Your plan does not include the Shopify integration.'], 403);
        }

        $data = $request->validate([
            'template_id' => 'required|integer',
            'days'        => 'nullable|integer|min:0|max:365',   // 0 = any time (pure segment, no recency)
            'min_orders'  => 'nullable|integer|min:0|max:1000',
            'min_spent'   => 'nullable|numeric|min:0',
            'coupon_code' => 'nullable|string|max:64',
        ]);
        $days      = (int) ($data['days'] ?? 60);
        $minOrders = (int) ($data['min_orders'] ?? 0);
        $minSpent  = (float) ($data['min_spent'] ?? 0);
        $tpl  = WaTemplate::where('workspace_id', $integration->workspace_id)->find($data['template_id']);
        if (!$tpl) return response()->json(['ok' => false, 'message' => 'Template not found.']);

        // Smart segment from order history: recency (lapsed) + min orders + min spend.
        $q = \App\Models\WaOrder::where('workspace_id', $integration->workspace_id)
            ->whereNotNull('customer_phone')->where('customer_phone', '!=', '')
            ->groupBy('customer_phone')
            ->selectRaw('customer_phone, COUNT(*) as o, SUM(total_minor) as s, MAX(created_at) as last_order');
        if ($days > 0)      $q->havingRaw('MAX(created_at) < ?', [now()->subDays($days)]);
        if ($minOrders > 0) $q->havingRaw('COUNT(*) >= ?', [$minOrders]);
        if ($minSpent > 0)  $q->havingRaw('SUM(total_minor) >= ?', [(int) round($minSpent * 100)]);
        $phones = $q->pluck('customer_phone');

        if ($phones->isEmpty()) {
            return response()->json(['ok' => false, 'message' => 'No customers match this segment.']);
        }

        // Drop opted-out numbers.
        $optedOut = \App\Models\Contact::where('workspace_id', $integration->workspace_id)
            ->where('is_unsubscribed', true)->get(['mobile'])
            ->map(fn ($c) => preg_replace('/\D+/', '', (string) $c->mobile))->filter()->all();

        $notifier = app(\App\Services\Commerce\CommerceEventNotifier::class);
        $sent = 0; $fail = 0;
        foreach ($phones->unique() as $phone) {
            $digits = preg_replace('/\D+/', '', (string) $phone);
            if ($digits === '' || in_array($digits, $optedOut, true)) { continue; }
            $ctx = [
                'name' => 'there', 'coupon_code' => (string) ($data['coupon_code'] ?? ''),
                'coupon' => (string) ($data['coupon_code'] ?? ''),
                'store_name' => (string) ($integration->store_name ?: $integration->store_url),
                '_positional' => ['there', $integration->store_name ?: $integration->store_url, $data['coupon_code'] ?? ''],
            ];
            $r = $notifier->notify($integration->workspace_id, $integration->user_id, $digits, $tpl, $ctx);
            ($r['ok'] ?? false) ? $sent++ : $fail++;
        }

        ShopifyIntegrationLog::create([
            'integration_id' => $integration->id,
            'event_type'     => 'winback/broadcast',
            'status'         => $sent > 0 ? 'sent' : 'failed',
            'recipient'      => $phones->count() . ' lapsed (>' . $days . 'd)',
            'payload'        => ['days' => $days, 'coupon' => $data['coupon_code'] ?? null, 'template' => $tpl->template_name],
            'response'       => ['sent' => $sent, 'failed' => $fail],
            'created_at'     => now(),
        ]);

        return response()->json(['ok' => true, 'sent' => $sent, 'failed' => $fail, 'total' => $phones->count()]);
    }

    /**
     * Variable context for an offer broadcast — product + coupon + contact.
     * Positional default: [customer name, product name, coupon/price].
     */
    private function offerContext(ShopifyIntegration $integration, $product, ?string $coupon, $contact): array
    {
        $name     = trim((string) ($contact->first_name ?: $contact->name ?: 'there'));
        $currency = $integration->shop_currency ?: '';
        $priceFmt = $product ? trim(number_format($product->price_minor / 100, 2) . ' ' . $currency) : '';

        return [
            'name'         => $name,
            'first_name'   => $name,
            'product_name' => $product?->name ?? '',
            'price'        => $priceFmt,
            'product_url'  => $product?->product_url ?? '',
            'coupon_code'  => (string) ($coupon ?? ''),
            'coupon'       => (string) ($coupon ?? ''),   // alias — templates use either {{coupon_code}} or {{coupon}}
            'store_name'   => (string) ($integration->store_name ?: $integration->store_url),
            'currency'     => $currency,
            '_positional'  => [$name, $product?->name ?? '', $coupon ?: $priceFmt],
        ];
    }

    /**
     * POST /shopify/webhook/{secret} — Shopify webhook receiver.
     *
     * We verify the X-Shopify-Hmac-SHA256 header before parsing.
     * No queues per project rule — we log and return 200 fast; the
     * actual messaging dispatch happens inline. If a template isn't
     * configured for the event we still log it as 'skipped'.
     */
    public function webhook(string $secret, Request $request): Response
    {
        $topicHdr = (string) $request->header('X-Shopify-Topic', '');
        $shopHdr  = (string) $request->header('X-Shopify-Shop-Domain', '');

        $integration = ShopifyIntegration::where('webhook_secret', $secret)->first();
        if (!$integration) {
            // A real order fired a webhook but we have no store for this secret
            // — usually the store was reconnected (new secret) and Shopify still
            // holds an OLD webhook subscription, or the URL is stale. Invisible
            // until now; log it so "orders don't trigger" is diagnosable.
            Log::warning('[Shopify-webhook] no integration for secret', [
                'topic' => $topicHdr, 'shop' => $shopHdr, 'secret_tail' => substr($secret, -6),
            ]);
            return response('not found', 404);
        }

        $payload = $request->getContent();
        $hmac    = (string) $request->header('X-Shopify-Hmac-SHA256', '');
        // Bind this store's workspace so a store connected through its OWN
        // Shopify app is verified with that app's secret (no session here).
        $svc = $this->shopify->forWorkspace($integration->workspace_id);
        if (!$svc->verifyWebhookSignature($payload, $hmac)) {
            // HMAC mismatch = Shopify's signing secret ≠ the app secret we verify
            // with. `secret_configured:false` means NO Shopify client secret is
            // set at all (Admin → Settings → the Shopify app) — then EVERY real
            // order fails here and Shopify eventually deletes the webhook. Shopify
            // retries a few times then gives up, so the merchant sees "nothing".
            Log::warning('[Shopify-webhook] HMAC verification FAILED — rejecting', [
                'workspace'        => $integration->workspace_id,
                'topic'            => $topicHdr,
                'shop'             => $shopHdr,
                'hmac_present'     => $hmac !== '',
                'secret_configured'=> $svc->hasWebhookSecret(),
                'body_len'         => strlen($payload),
            ]);
            return response('bad hmac', 401);
        }

        $topic = $topicHdr;
        $data  = json_decode($payload, true) ?: [];

        $event = ShopifyIntegrationEvent::where('integration_id', $integration->id)
            ->where('event_type', $topic)
            ->first();

        $shouldSend = $event && $event->is_active && $event->template_id;

        // Full receipt trail: proves the webhook ARRIVED + verified, and shows
        // exactly why it will or won't send. "no event" / "inactive" / "no
        // template" here means the automation is not set up for this topic —
        // not that delivery is broken.
        Log::info('[Shopify-webhook] received', [
            'workspace'    => $integration->workspace_id,
            'integration'  => $integration->id,
            'topic'        => $topic,
            'shop'         => $shopHdr,
            'event_found'  => (bool) $event,
            'is_active'    => (bool) ($event->is_active ?? false),
            'template_id'  => $event->template_id ?? null,
            'will_send'    => $shouldSend,
        ]);

        $log = ShopifyIntegrationLog::create([
            'integration_id' => $integration->id,
            'event_type'     => $topic,
            'status'         => $shouldSend ? 'processed' : 'skipped',
            'recipient'      => $this->resolveRecipient($data, $this->storeCountryIso($integration)),
            'payload'        => $data,
            'created_at'     => now(),
        ]);

        // Fire the configured WhatsApp template — engine-aware (Unofficial
        // API / WABA / Twilio). Wrapped so a send failure NEVER makes
        // Shopify retry the webhook (which would duplicate the log + send).
        if ($shouldSend) {
            try {
                $this->dispatchEventMessage($integration, $event, $data, $log);
            } catch (\Throwable $e) {
                Log::warning('[Shopify-webhook] dispatch crashed (swallowed): ' . $e->getMessage());
                $log->update(['status' => 'failed', 'error' => $e->getMessage()]);
            }
        }

        // Back-in-stock — detect an out→in transition BEFORE the mirror
        // upsert overwrites the previous stock state, and message anyone
        // on the waitlist for this product.
        if ($topic === 'products/update' && !empty($data['id'])) {
            try {
                app(\App\Services\Shopify\ShopifyStockService::class)->handleProductUpdate($integration, $data);
            } catch (\Throwable $e) {
                \Log::warning('[Shopify-webhook] back-in-stock failed (swallowed): ' . $e->getMessage());
            }
        }

        // Keep our local mirror in sync as Shopify changes. Best-effort,
        // wrapped so a mapping bug never makes Shopify retry the webhook.
        try {
            $importer = app(\App\Services\Shopify\ShopifyImporter::class);
            if ($topic === 'products/update' && !empty($data['id'])) {
                $importer->upsertProduct($integration, $data);
            } elseif (in_array($topic, ['orders/create', 'orders/updated', 'orders/paid', 'orders/fulfilled', 'orders/cancelled'], true) && !empty($data['id'])) {
                $waOrder = $importer->upsertOrder($integration, $data);
                // Auto-invoice — issue-only + mark pending (fast); sweep renders+sends.
                // Default trigger orders/paid; also honor a financial_status=paid.
                $paid = $topic === 'orders/paid' || strtolower((string) ($data['financial_status'] ?? '')) === 'paid';
                if ($waOrder && $paid) {
                    app(\App\Services\Invoice\InvoiceService::class)->handleWebhookOrder(
                        $waOrder, 'shopify',
                        (string) $request->header('X-Shopify-Webhook-Id', ''),
                        $topic, $data   // raw order → correct tax/line/currency mapping
                    );
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('[Shopify-webhook] local mirror failed (swallowed): ' . $e->getMessage());
        }

        // COD double-confirmation — on a new cash-on-delivery order, if the
        // merchant has the COD automation active, message the customer to
        // confirm (Yes/No) and open a pending tracking row.
        if ($topic === 'orders/create' && \App\Services\Shopify\ShopifyCodService::isCodOrder($data)) {
            try {
                $codEvent = ShopifyIntegrationEvent::where('integration_id', $integration->id)
                    ->where('event_type', 'cod/confirm')->where('is_active', true)->first();
                if ($codEvent && $codEvent->template_id) {
                    app(\App\Services\Shopify\ShopifyCodService::class)->sendConfirmation($integration, $codEvent, $data);
                }
                // COD → Prepaid nudge: offer to pay online now (template uses
                // {{order_url}} + a discount the merchant bakes in).
                $prepaid = ShopifyIntegrationEvent::where('integration_id', $integration->id)
                    ->where('event_type', 'cod/prepaid')->where('is_active', true)->first();
                if ($prepaid && $prepaid->template_id) {
                    $this->sendPseudoEvent($integration, $prepaid, $this->resolveRecipient($data, $this->storeCountryIso($integration)), $this->orderContext($integration, $data), 'cod/prepaid');
                }
            } catch (\Throwable $e) {
                \Log::warning('[Shopify-webhook] COD confirm/prepaid failed (swallowed): ' . $e->getMessage());
            }
        }

        // Delivered — fulfillments/update carries shipment_status. When the
        // courier marks it delivered, fire the Delivered automation (resolve
        // the customer from our mirrored order).
        if ($topic === 'fulfillments/update' && strtolower((string) ($data['shipment_status'] ?? '')) === 'delivered') {
            try {
                $dEvent = ShopifyIntegrationEvent::where('integration_id', $integration->id)
                    ->where('event_type', 'order/delivered')->where('is_active', true)->first();
                if ($dEvent && $dEvent->template_id) {
                    $waOrder = \App\Models\WaOrder::where('workspace_id', $integration->workspace_id)
                        ->where('shopify_order_id', (string) ($data['order_id'] ?? ''))->first();
                    $phone = $waOrder?->customer_phone ?: ($data['destination']['phone'] ?? null);
                    $name  = $waOrder?->customer_name ?: 'there';
                    $oName = $waOrder?->meta_json['name'] ?? ('#' . ($data['order_id'] ?? ''));
                    $ctx = [
                        'name' => $name, 'first_name' => $name, 'order_name' => $oName,
                        'store_name' => (string) ($integration->store_name ?: $integration->store_url),
                        '_positional' => [$name, $oName, ''],
                    ];
                    $this->sendPseudoEvent($integration, $dEvent, $phone, $ctx, 'order/delivered');
                }
            } catch (\Throwable $e) {
                \Log::warning('[Shopify-webhook] delivered failed (swallowed): ' . $e->getMessage());
            }
        }

        // Abandoned-cart recovery — schedule the delayed follow-up steps on
        // a new checkout; cancel them the moment the order is placed/paid.
        try {
            $cart = app(\App\Services\Shopify\ShopifyCartService::class);
            if ($topic === 'checkouts/create') {
                $cart->scheduleSequence($integration, $data);
            } elseif (in_array($topic, ['orders/create', 'orders/paid'], true)) {
                $cart->cancelOnOrder($integration, $data);
            }
        } catch (\Throwable $e) {
            \Log::warning('[Shopify-webhook] cart recovery failed (swallowed): ' . $e->getMessage());
        }

        // Commerce-flow loop closer — orders created via the flow
        // builder's commerce node carry the flow session in the cart
        // `note` (we set it on cartCreate / draft_order). Resolve it
        // and ping Node to advance through the `purchased` port.
        // Wrapped — a resolver bug must NEVER trigger Shopify to retry
        // the webhook (which would duplicate the log we just wrote).
        if (in_array($topic, ['orders/create', 'orders/paid'], true)) {
            try {
                \App\Services\Commerce\FlowSessionResolver::resumeFromShopifyOrder($data);
            } catch (\Throwable $e) {
                \Log::warning('[Shopify-webhook] flow-resume crashed (swallowed): ' . $e->getMessage());
            }
        }

        // Merchant uninstalled the app → revoke + clear our copy of
        // the access token. The integration row stays so its logs +
        // event map remain visible in /shopify, but isConnected() now
        // returns false (no access_token) so we stop making API calls.
        if ($topic === 'app/uninstalled') {
            try {
                $integration->update([
                    'access_token' => null,
                    'connected_at' => null,
                ]);
            } catch (\Throwable $e) {
                \Log::warning('[Shopify-webhook] uninstall cleanup failed: ' . $e->getMessage());
            }
        }

        return response('ok', 200);
    }

    /**
     * POST /shopify/compliance — the three MANDATORY GDPR compliance webhooks
     * every Shopify app must expose (customers/data_request, customers/redact,
     * shop/redact). Shopify's automated app checks + reviewers probe this with a
     * deliberately BAD HMAC (must → 401) and a valid one (must → 200), with NO
     * store installed — so unlike the per-integration receiver above this route
     * carries no {secret} and never looks a row up before verifying.
     *
     * HMAC is over the raw body, keyed by the app-level shopify_client_secret
     * (same as verifyWebhookSignature). Topic comes from X-Shopify-Topic.
     */
    public function compliance(Request $request): Response
    {
        $payload = $request->getContent();
        $hmac    = (string) $request->header('X-Shopify-Hmac-SHA256', '');

        // Bind the store's workspace (from the shop-domain header) so a store on
        // its OWN Shopify app is verified with that app's secret. The domain
        // header is trusted only to pick a candidate secret — the HMAC below is
        // still what authenticates the request. Unknown shop → global secret.
        $shopHeader = (string) $request->header('X-Shopify-Shop-Domain', '');
        if ($shopHeader !== '') {
            $wsId = ShopifyIntegration::where('store_url', $shopHeader)->value('workspace_id');
            if ($wsId) $this->shopify->forWorkspace((int) $wsId);
        }

        // Fail-closed: missing/invalid signature → 401 BEFORE any parsing.
        if (!$this->shopify->verifyWebhookSignature($payload, $hmac)) {
            return response('bad hmac', 401);
        }

        $topic = (string) $request->header('X-Shopify-Topic', '');
        $data  = json_decode($payload, true) ?: [];
        $shop  = (string) ($data['shop_domain'] ?? $request->header('X-Shopify-Shop-Domain', ''));

        // Handle each topic best-effort — never 500, or Shopify retries. We ACK
        // fast with 200 once the signature is proven authentic.
        try {
            switch ($topic) {
                case 'customers/data_request':
                    // We hold no customer PII beyond webhook logs; nothing to
                    // compile. Record the request so it's auditable, then ACK.
                    Log::info('[Shopify-compliance] customers/data_request', [
                        'shop' => $shop, 'customer' => $data['customer']['id'] ?? null,
                    ]);
                    break;

                case 'customers/redact':
                    // Erase what we store about this customer: webhook log rows
                    // (recipient phone + payload) for this shop's integration(s).
                    $custPhone = (string) ($data['customer']['phone'] ?? '');
                    foreach (ShopifyIntegration::where('store_url', $shop)->get() as $integration) {
                        $q = ShopifyIntegrationLog::where('integration_id', $integration->id);
                        if ($custPhone !== '') $q->where('recipient', $custPhone);
                        $q->delete();
                    }
                    Log::info('[Shopify-compliance] customers/redact done', ['shop' => $shop]);
                    break;

                case 'shop/redact':
                    // Store uninstalled 48h ago → erase ALL data we hold for it:
                    // the integration row(s) and their events + logs.
                    foreach (ShopifyIntegration::where('store_url', $shop)->get() as $integration) {
                        ShopifyIntegrationLog::where('integration_id', $integration->id)->delete();
                        ShopifyIntegrationEvent::where('integration_id', $integration->id)->delete();
                        $integration->delete();
                    }
                    Log::info('[Shopify-compliance] shop/redact done', ['shop' => $shop]);
                    break;

                default:
                    Log::info('[Shopify-compliance] unhandled topic', ['topic' => $topic, 'shop' => $shop]);
            }
        } catch (\Throwable $e) {
            // Signature was valid, so ACK regardless — log the failure for follow-up.
            Log::warning('[Shopify-compliance] handler failed (swallowed): ' . $e->getMessage());
        }

        return response('ok', 200);
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    private function ownedIntegration(int $id): ?ShopifyIntegration
    {
        $user = Auth::user();
        $wsId = $user?->current_workspace_id;
        if (!$wsId) return null;
        return ShopifyIntegration::where('workspace_id', $wsId)->find($id);
    }

    /**
     * The ordered placeholders in a template body — positional ({{1}}) and
     * named ({{City}}, {{Order ID}}) alike — as [{raw, key, numeric}]. Uses
     * the same token regex the campaign builder uses, so every variable a
     * merchant can map is discovered (not just numeric ones).
     *
     * @return array<int, array{raw:string,key:string,numeric:bool}>
     */
    private function templateTokens(?string $body): array
    {
        $body = (string) $body;
        if ($body === '') return [];
        if (!preg_match_all(\App\Services\TemplateOverrideResolver::TOKEN_RE, $body, $m)) return [];
        $out = [];
        foreach ($m[1] as $raw) {
            $raw = trim((string) $raw);
            if ($raw === '') continue;
            $out[] = [
                'raw'     => $raw,
                'key'     => \App\Services\TemplateOverrideResolver::normalizeKey($raw),
                'numeric' => ctype_digit($raw),
            ];
        }
        return $out;
    }

    /**
     * Apply a per-event var_map (ordered order-field keys, one per template
     * placeholder) onto the message context. Positional {{n}} params are fed
     * through $ctx['_positional']; NAMED tokens ({{City}}) are additionally
     * written under their own key so CommerceEventNotifier::renderBody (which
     * matches by name, case-insensitively) fills them too. No var_map → ctx
     * is returned unchanged (the default positional order still applies).
     */
    private function applyVarMap(array $ctx, WaTemplate $tpl, ShopifyIntegrationEvent $event): array
    {
        if (!is_array($event->var_map) || !$event->var_map) return $ctx;

        // The var_map is aligned to the template's placeholders IN ORDER (one
        // entry per picker). Resolve each token to its own field, and place a
        // NUMERIC token's value at its numeric slot ({{4}} → _positional[3]),
        // NOT at its appearance index — so a named token, an out-of-order
        // {{2}}/{{1}}, or a duplicate placeholder can't shift the amount onto
        // the order-id slot. Named tokens ({{City}}) are written under their key
        // for CommerceEventNotifier::renderBody's by-name match.
        $map        = array_values($event->var_map);
        $tokens     = array_values($this->templateTokens($tpl->template_body ?? ''));
        $positional = [];
        foreach ($tokens as $i => $tok) {
            $field = $map[$i] ?? '';
            $value = $field !== '' ? (string) ($ctx[$field] ?? '') : '';
            if (!empty($tok['numeric'])) {
                $positional[(int) $tok['raw'] - 1] = $value;
            } elseif (($tok['key'] ?? '') !== '') {
                $ctx[$tok['key']] = $value;
            }
        }
        if ($positional) {
            // Fill gaps so the consumer sees a contiguous 0..max array.
            $max = max(array_keys($positional));
            for ($j = 0; $j <= $max; $j++) {
                if (!array_key_exists($j, $positional)) $positional[$j] = '';
            }
            ksort($positional);
            $ctx['_positional'] = array_values($positional);
        }
        return $ctx;
    }

    private function resolveRecipient(array $data, ?string $fallbackIso = null): ?string
    {
        // Shopify stores whatever the customer typed — often a LOCAL number with
        // no country code ("9876543210"). Sending that to WhatsApp targets a
        // non-existent international address, so the message "fires" but silently
        // never delivers (the #1 reason a Shopify automation "doesn't work").
        // Normalise to E.164 using the order's country (ISO-2) — same shared
        // helper WooCommerce uses — prepending the dialing code when needed.
        $rawPhone = $data['customer']['phone']
            ?? $data['phone']
            ?? ($data['shipping_address']['phone'] ?? null)
            ?? ($data['billing_address']['phone'] ?? null)
            ?? null;

        // Order country first; then the store's own country (an Indian store's
        // customers are Indian), then the platform default. Without this a local
        // number on an order that omitted country_code stayed un-prefixed and
        // never reached a new customer.
        $iso = ($data['shipping_address']['country_code'] ?? null)
            ?? ($data['billing_address']['country_code'] ?? null)
            ?? ($data['customer']['default_address']['country_code'] ?? null)
            ?? $fallbackIso;

        $normalized = \App\Support\Woo\WooPhone::e164($rawPhone, $iso);

        \Log::info('[SHOPIFY-AUTO] recipient resolved', [
            'raw'         => $rawPhone,
            'country_iso' => $iso,
            'fallback'    => $fallbackIso,
            'normalized'  => $normalized,
            'changed'     => $normalized !== preg_replace('/\D+/', '', (string) $rawPhone),
        ]);

        return $normalized;
    }

    /**
     * Best ISO-3166 alpha-2 for a store's customers when an order omits the
     * country: the store's own country (Shopify gives us its NAME, e.g.
     * "India"), else the platform default_country_iso (falls back to 'in').
     */
    private function storeCountryIso(ShopifyIntegration $integration): ?string
    {
        $name = trim((string) ($integration->shop_country ?? ''));
        if ($name !== '') {
            foreach ((array) config('countries', []) as $c) {
                $label = preg_replace('/\s*\(\+.*$/', '', (string) ($c['label'] ?? ''));
                if (strcasecmp(trim((string) $label), $name) === 0) {
                    return strtoupper((string) ($c['iso'] ?? ''));
                }
            }
        }
        $def = strtoupper(trim((string) \App\Models\SystemSetting::get('default_country_iso', 'in')));
        return $def !== '' ? $def : null;
    }

    /**
     * Send the configured template for a fired event to the customer
     * and/or the merchant's admin number, then record the outcome on the
     * log row. delay_seconds is not honoured inline (a webhook must return
     * fast) — the send fires immediately; scheduled delays are a future
     * enhancement via the Node scheduler.
     */
    private function dispatchEventMessage(
        ShopifyIntegration $integration,
        ShopifyIntegrationEvent $event,
        array $data,
        ShopifyIntegrationLog $log
    ): void {
        $tpl = WaTemplate::where('workspace_id', $integration->workspace_id)
            ->find($event->template_id);
        if (!$tpl) {
            $log->update(['status' => 'failed', 'error' => 'Configured template no longer exists.']);
            return;
        }

        $ctx       = $this->orderContext($integration, $data);

        // Per-event variable mapping wins over the positional default:
        // var_map is an ordered list of order fields → each template
        // placeholder (positional {{1}} and named {{City}} alike).
        $ctx = $this->applyVarMap($ctx, $tpl, $event);

        $sendTo     = $event->send_to ?: 'customer';
        $notifier  = app(\App\Services\Commerce\CommerceEventNotifier::class);

        $targets = [];
        if (in_array($sendTo, ['customer', 'both'], true)) {
            $customer = $this->resolveRecipient($data, $this->storeCountryIso($integration));
            if ($customer) $targets['customer'] = $customer;
        }
        if (in_array($sendTo, ['admin', 'both'], true) && $event->admin_number) {
            $targets['admin'] = $event->admin_number;
        }

        if (empty($targets)) {
            $log->update(['status' => 'failed', 'error' => 'No recipient — order has no customer phone' . ($sendTo === 'admin' ? '' : ' and no admin number set') . '.']);
            return;
        }

        // DELAYED send: honour the event's delay_seconds by dispatching each send
        // as a delayed job (needs Advanced Scaling / a queue worker — see Scaling).
        // With the default sync connection the delay is ignored and it sends now,
        // which is exactly the previous immediate behaviour, so this is additive.
        $delay = (int) ($event->delay_seconds ?? 0);
        if ($delay > 0 && \App\Support\Scaling::enabled()) {
            foreach ($targets as $number) {
                \App\Jobs\SendCommerceEventJob::dispatch($integration->workspace_id, $integration->user_id, (string) $number, (int) $tpl->id, $ctx)
                    ->onConnection(\App\Support\Scaling::queueConnection())
                    ->onQueue('bulk')
                    ->delay(now()->addSeconds($delay));
            }
            $log->update(['status' => 'scheduled', 'recipient' => implode(', ', array_values($targets)), 'error' => null]);
            return;
        }

        $results  = [];
        $anyOk     = false;
        foreach ($targets as $who => $number) {
            $r = $notifier->notify($integration->workspace_id, $integration->user_id, $number, $tpl, $ctx);
            $results[$who] = $r;
            $anyOk = $anyOk || ($r['ok'] ?? false);
        }

        $errors = collect($results)
            ->filter(fn ($r) => !($r['ok'] ?? false))
            ->map(fn ($r, $who) => $who . ': ' . ($r['error'] ?? 'failed'))
            ->values()->all();

        $log->update([
            'status'    => $anyOk ? 'sent' : 'failed',
            'recipient' => implode(', ', array_values($targets)),
            'response'  => $results,
            'error'     => $errors ? implode(' | ', $errors) : null,
        ]);
    }

    /**
     * Flatten a Shopify order/customer webhook payload into the named +
     * positional variable map CommerceEventNotifier substitutes into the
     * template. Positional default order is [customer name, order number,
     * total] — the common "Hi {{1}}, order {{2}} for {{3}} confirmed" shape.
     */

    /**
     * Fire a configured automation that isn't a 1:1 webhook topic
     * (cod/prepaid, order/delivered) — send the template to one recipient
     * with a prebuilt context, honour the event's var_map, and log it.
     */
    private function sendPseudoEvent(ShopifyIntegration $integration, ShopifyIntegrationEvent $event, ?string $phone, array $ctx, string $logType): void
    {
        $phone = $phone ? preg_replace('/\D+/', '', (string) $phone) : '';
        if ($phone === '') return;
        $tpl = WaTemplate::where('workspace_id', $integration->workspace_id)->find($event->template_id);
        if (!$tpl) return;
        $ctx = $this->applyVarMap($ctx, $tpl, $event);
        $r = app(\App\Services\Commerce\CommerceEventNotifier::class)
            ->notify($integration->workspace_id, $integration->user_id, $phone, $tpl, $ctx);
        ShopifyIntegrationLog::create([
            'integration_id' => $integration->id,
            'event_type'     => $logType,
            'status'         => ($r['ok'] ?? false) ? 'sent' : 'failed',
            'recipient'      => $phone,
            'payload'        => ['order' => $ctx['order_name'] ?? null],
            'response'       => $r,
            'error'          => ($r['ok'] ?? false) ? null : ($r['error'] ?? null),
            'created_at'     => now(),
        ]);
    }

    private function orderContext(ShopifyIntegration $integration, array $data): array
    {
        $cust      = is_array($data['customer'] ?? null) ? $data['customer'] : [];
        $first     = trim((string) ($cust['first_name'] ?? ($data['first_name'] ?? '')));
        $last      = trim((string) ($cust['last_name']  ?? ($data['last_name']  ?? '')));
        $name      = trim($first . ' ' . $last) ?: ($data['name'] ?? 'there');
        $orderName = (string) ($data['name'] ?? ('#' . ($data['order_number'] ?? $data['id'] ?? '')));
        $currency  = (string) ($data['currency'] ?? $integration->shop_currency ?? '');
        $total     = (string) ($data['total_price'] ?? '');
        $totalFmt  = $total !== '' ? trim($total . ' ' . $currency) : '';

        // Fulfilment / tracking — for the Shipped + Delivered automations.
        $ful      = is_array($data['fulfillments'][0] ?? null) ? $data['fulfillments'][0] : [];
        $orderUrl = (string) ($data['order_status_url'] ?? '');

        return [
            'name'         => $name,
            'first_name'   => $first ?: $name,
            'last_name'    => $last,
            'order_number' => (string) ($data['order_number'] ?? $data['id'] ?? ''),
            'order_name'   => $orderName,
            'total'        => $totalFmt,
            'total_price'  => $total,
            'currency'     => $currency,
            'email'        => (string) ($cust['email'] ?? $data['email'] ?? ''),
            'store_name'   => (string) ($integration->store_name ?: $integration->store_url),
            'financial_status'    => (string) ($data['financial_status'] ?? ''),
            'fulfillment_status'  => (string) ($data['fulfillment_status'] ?? ''),
            // Tracking + order URL — usable as {{tracking_url}}, {{tracking_number}},
            // {{tracking_company}}, {{order_url}} in Shipped/Delivered/Prepaid templates.
            'tracking_url'     => (string) ($ful['tracking_url'] ?? ($ful['tracking_urls'][0] ?? $orderUrl)),
            'tracking_number'  => (string) ($ful['tracking_number'] ?? ''),
            'tracking_company' => (string) ($ful['tracking_company'] ?? ''),
            'order_url'        => $orderUrl,
            'checkout_url'     => (string) ($data['abandoned_checkout_url'] ?? $orderUrl),
            // Positional fallback for numeric {{1}}/{{2}}/{{3}} templates.
            '_positional'  => [$name, $orderName, $totalFmt],
        ];
    }

    private function dashboardData(ShopifyIntegration $integration): array
    {
        // LIVE — store figures come straight from the Shopify Admin API, not
        // from our mirrored tables. The mirror only ever held a snapshot from
        // the last successful sync, so a store whose token had been revoked
        // still rendered a healthy-looking dashboard built on stale numbers.
        // Reading live means a broken connection shows as broken.
        //
        // Only store data moves; webhook logs, event mappings, templates,
        // segments and coupons stay on our side because they ARE ours.
        $wsId = $integration->workspace_id;

        $liveProducts  = $this->shopify->getProducts($integration, 120);
        $liveOrders    = $this->shopify->getOrders($integration, 250);
        $liveCustomers = $this->shopify->getCustomers($integration, 50);
        $counts        = $this->shopify->getStoreCounts($integration);

        // First failure across those calls, translated into a cause + fix the
        // merchant can act on. Null when Shopify answered everything.
        $apiError      = $this->shopify->lastError();
        $shopifyError  = $apiError ? ShopifyService::diagnose($apiError) : null;

        // A store that cannot be read has no trustworthy figures. Zero them so
        // the page never presents last-known numbers as if they were current.
        if ($shopifyError) {
            $counts = ['products' => 0, 'orders' => 0, 'customers' => 0];
            $integration->update(['status' => 'error']);
        }

        $storeHost = $integration->store_url;

        $productModels = collect($liveProducts);

        // Flatten a live Shopify product into the flat shape the tab markup
        // reads. Shopify nests price on the first variant and the image under
        // images[]/image, so both are lifted here rather than in Blade.
        $mapProduct = function (array $p) use ($storeHost) {
            $variant = $p['variants'][0] ?? [];
            $price   = (float) ($variant['price'] ?? 0);
            $compare = isset($variant['compare_at_price']) && $variant['compare_at_price'] !== null
                ? (float) $variant['compare_at_price']
                : null;
            $off     = ($compare && $compare > $price) ? (int) round((1 - $price / $compare) * 100) : 0;
            $img     = $p['images'][0]['src'] ?? ($p['image']['src'] ?? null);

            return [
                'id'            => $p['id'] ?? null,
                'title'         => $p['title'] ?? '',
                'handle'        => $p['handle'] ?? '',
                'vendor'        => $p['vendor'] ?? '',
                'product_type'  => $p['product_type'] ?? '',
                'status'        => $p['status'] ?? '',
                'images'        => $p['images'] ?? [],
                'image_url'     => $img,
                'product_url'   => ($p['handle'] ?? '') !== '' ? 'https://' . $storeHost . '/products/' . $p['handle'] : '',
                'price'         => $price,
                'compare_price' => $compare,
                'discount_pct'  => $off,
                'in_stock'      => ($p['status'] ?? '') === 'active',
                'variants'      => $p['variants'] ?? [],
                'created_at'    => $p['created_at'] ?? null,
            ];
        };

        $mapped      = $productModels->map($mapProduct)->values();
        $products    = $mapped->all();
        $offers      = $mapped->filter(fn ($p) => $p['discount_pct'] > 0)->values()->take(8)->all();
        $newArrivals = $mapped->sortByDesc('created_at')->values()->take(8)->all();
        $popular     = $mapped->sortByDesc('price')->values()->take(8)->all();

        // Live orders already arrive in the shape the tabs read. The full pull
        // (up to 250) feeds the analytics trend below; the tables show the
        // newest 20, which is what they showed before.
        $allOrders = collect($liveOrders)
            ->sortByDesc(fn ($o) => strtotime((string) ($o['created_at'] ?? '')))
            ->values();
        $orders = $allOrders->take(20)->all();

        // Live customers already match the customers table's field names.
        $customers = collect($liveCustomers)->values()->all();

        $logs = ShopifyIntegrationLog::where('integration_id', $integration->id);
        $logTotal     = (clone $logs)->count();
        $logsByStatus = (clone $logs)
            ->selectRaw('status, COUNT(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status')
            ->toArray();
        $logsByEvent  = (clone $logs)
            ->selectRaw('event_type, COUNT(*) as n')
            ->groupBy('event_type')
            ->pluck('n', 'event_type')
            ->toArray();
        $recentLogs = (clone $logs)
            ->latest('created_at')
            ->limit(15)
            ->get();

        $eventsByType = ShopifyIntegrationEvent::where('integration_id', $integration->id)
            ->get()
            ->keyBy('event_type');

        $activeEvents = $eventsByType->where('is_active', true)->count();

        $templates = WaTemplate::query()
            ->where('workspace_id', $integration->workspace_id)
            ->approved()
            ->with('provider')
            ->orderBy('template_name')
            ->get(['id', 'template_name', 'category', 'language', 'template_body', 'channel', 'provider_config_id', 'meta_template_id', 'twilio_content_sid']);

        // For the per-event variable-mapping UI. The ordered list of EVERY
        // placeholder each template body declares — positional ({{1}}) AND
        // named ({{City}}, {{Order ID}}) — so the merchant can link an order
        // field (customer name, price/amount, order number …) to each one.
        // Named tokens were previously invisible here (the count only looked
        // for {{digits}}), so those templates showed no mapping option at all.
        $templateTokens = $templates->mapWithKeys(
            fn ($t) => [$t->id => $this->templateTokens($t->template_body)]
        )->toArray();
        // Kept for anything still reading a bare count (= number of tokens).
        $templateParamCounts = collect($templateTokens)
            ->map(fn ($toks) => count($toks))->toArray();

        // ---- Analytics ----
        // Revenue and the trend come from the live order pull; message and
        // offer counts stay ours, because we are the ones who sent them.
        $revenueTotal = (int) round($allOrders->sum(fn ($o) => (float) ($o['total_price'] ?? 0)) * 100);
        $ordersTotal  = $counts['orders'];
        $messagesSent = ShopifyIntegrationLog::where('integration_id', $integration->id)->where('status', 'sent')->count();
        $offersSent   = ShopifyIntegrationLog::where('integration_id', $integration->id)->where('event_type', 'offer/broadcast')->count();

        // 14-day revenue trend from the live orders.
        $since = now()->subDays(13)->startOfDay();
        $byDay = $allOrders
            ->filter(fn ($o) => ($ts = strtotime((string) ($o['created_at'] ?? ''))) && $ts >= $since->getTimestamp())
            ->groupBy(fn ($o) => date('Y-m-d', strtotime((string) $o['created_at'])))
            ->map(fn ($g) => collect($g)->sum(fn ($o) => (float) ($o['total_price'] ?? 0)));
        $trend = [];
        for ($d = 0; $d < 14; $d++) {
            $key = now()->subDays(13 - $d)->format('Y-m-d');
            $trend[] = ['label' => now()->subDays(13 - $d)->format('M j'), 'value' => (float) ($byDay[$key] ?? 0)];
        }

        // Impact / ROI — real, attributable numbers (no fabricated revenue).
        $aov          = $ordersTotal ? ($revenueTotal / 100 / $ordersTotal) : 0;
        $codConfirmed = \App\Models\ShopifyCodConfirmation::where('workspace_id', $wsId)->where('status', 'confirmed')->count();
        $codCancelled = \App\Models\ShopifyCodConfirmation::where('workspace_id', $wsId)->where('status', 'cancelled')->count();
        $recoverySends = ShopifyIntegrationLog::where('integration_id', $integration->id)
            ->whereIn('event_type', ['offer/broadcast', 'winback/broadcast', 'checkouts/create', 'cod/confirm', 'cod/prepaid', 'stock/back'])
            ->where('status', 'sent')->count();

        $analytics = [
            'revenue_total' => $revenueTotal / 100,
            'orders_total'  => $ordersTotal,
            'aov'           => $aov,
            'messages_sent' => $messagesSent,
            'offers_sent'   => $offersSent,
            'trend'         => $trend,
            'trend_max'     => max(1, collect($trend)->max('value')),
            // Impact: COD confirmations protect revenue; cancellations are RTO avoided.
            'cod_confirmed' => $codConfirmed,
            'cod_cancelled' => $codCancelled,
            'cod_protected' => $codConfirmed * $aov,
            'rto_avoided'   => $codCancelled * $aov,
            'recovery_sends'=> $recoverySends,
        ];

        // Offer-composer pickers: contact groups (segments) + this workspace's
        // OWN store coupons. Must be WaCoupon (workspace-scoped), NOT the admin
        // billing Coupon model — that has no workspace_id and would leak the
        // platform's subscription promo codes into every merchant's UI.
        $contactGroups = \App\Models\ContactGroup::where('workspace_id', $wsId)->get(['id', 'user_group', 'color']);
        $coupons = \App\Models\WaCoupon::where('workspace_id', $wsId)->where('active', true)
            ->orderBy('code')->limit(100)->get(['id', 'code', 'type', 'amount']);

        $revenue30d = collect($orders)->sum(fn ($o) => (float) ($o['total_price'] ?? 0));
        $currency   = $integration->shop_currency ?: ($orders[0]['currency'] ?? 'USD');

        return [
            'shopifyError'  => $shopifyError,
            'analytics'     => $analytics,
            'contactGroups' => $contactGroups,
            'coupons'       => $coupons,
            'counts'        => $counts,
            'orders'        => $orders,
            'products'      => $products,
            'customers'     => $customers,
            'logTotal'      => $logTotal,
            'logsByStatus'  => $logsByStatus,
            'logsByEvent'   => $logsByEvent,
            'recentLogs'    => $recentLogs,
            'eventsByType'  => $eventsByType,
            'activeEvents'  => $activeEvents,
            'offers'        => $offers,
            'newArrivals'   => $newArrivals,
            'popular'       => $popular,
            'templates'           => $templates,
            'templateParamCounts' => $templateParamCounts,
            'templateTokens'      => $templateTokens,
            'revenue30d'    => $revenue30d,
            'currency'      => $currency,
            // Chat-widget-on-storefront toggle state + the workspace's widgets.
            'chatWidgets'      => \App\Models\ChatbotWidget::where('workspace_id', $integration->workspace_id)
                                    ->where('status', 'active')->orderBy('name')->get(['id', 'name', 'embed_token']),
            'widgetEnabled'    => (bool) ($integration->metadata['widget_enabled'] ?? false),
            'widgetId'         => (int) ($integration->metadata['widget_id'] ?? 0),
        ];
    }

}
