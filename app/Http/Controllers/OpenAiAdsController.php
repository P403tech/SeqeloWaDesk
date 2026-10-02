<?php

namespace App\Http\Controllers;

use App\Models\OpenAiAdsAd;
use App\Models\OpenAiAdsAdGroup;
use App\Models\OpenAiAdsApiKey;
use App\Models\OpenAiAdsCampaign;
use App\Models\OpenAiAdsEventSetting;
use App\Models\OpenAiAdsPixel;
use App\Models\WaProviderConfig;
use App\Services\OpenAiAds\OpenAiAdsClient;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * OpenAI (ChatGPT) Ads — advertiser module. Each workspace connects its OWN
 * OpenAI Ads API key (one key == one ad account) and manages campaigns from
 * inside WaDesk. Mirrors the Meta Ads module: per-workspace credentials live on
 * a WaProviderConfig row (provider=openai_ads), encrypted at rest.
 *
 * P1 (this file): connect / verify / read-only list. Create + insights land in
 * later phases; the OpenAiAdsClient service already wraps every endpoint.
 */
class OpenAiAdsController extends Controller
{
    private function wsId(): int
    {
        return (int) (Auth::user()?->current_workspace_id ?? 0);
    }

    /** GET /openai-ads — account status + live campaign list (when connected). */
    public function index(Request $request): View
    {
        $wsId   = $this->wsId();
        $config = OpenAiAdsClient::configFor($wsId);
        $client = OpenAiAdsClient::forWorkspace($wsId);

        $account = (array) ($config?->meta_json['account'] ?? []);
        $error   = null;

        if ($client) {
            // Refresh the account snapshot (cheap) so review status stays current.
            $acc = $client->getAdAccount();
            if ($acc['ok']) {
                $account = $acc['data'];
                if ($config) {
                    $config->meta_json = array_merge((array) $config->meta_json, ['account' => $account]);
                    $config->save();
                }
            } elseif ($acc['error']) {
                $error = $acc['error'];
            }
        }

        // The manageable list is our LOCAL mirror (campaigns created here).
        $campaigns = OpenAiAdsCampaign::forWorkspace($wsId)
            ->withCount('adGroups')->orderByDesc('id')->get();

        return view('user.openai-ads.index', [
            'connected' => (bool) $client,
            'account'   => $account,
            'campaigns' => $campaigns,
            'error'     => $error,
        ]);
    }

    /** GET /openai-ads/connect — the "paste your API key" screen. */
    public function connect(): View
    {
        $config = OpenAiAdsClient::configFor($this->wsId());
        $hasKey = trim((string) ($config?->creds()['api_key'] ?? '')) !== '';

        return view('user.openai-ads.connect', [
            'hasKey'  => $hasKey,
            'account' => (array) ($config?->meta_json['account'] ?? []),
        ]);
    }

    /** POST /openai-ads/keys — store the workspace's Ads API key + verify it. */
    public function saveKeys(Request $request): RedirectResponse
    {
        $wsId = $this->wsId();
        if (! $wsId) abort(403, __('No workspace.'));

        $data = $request->validate([
            'api_key' => ['required', 'string', 'max:512'],
        ]);

        // Verify the key against the live account BEFORE saving, so a bad key is
        // rejected with a clear message instead of silently "connecting".
        $probe = (new OpenAiAdsClient(trim($data['api_key'])))->getAdAccount();
        if (! $probe['ok']) {
            return back()->withErrors(['api_key' => __('That key was rejected: :err', ['err' => $probe['error'] ?: __('could not reach the Ads API')])]);
        }

        $cfg = WaProviderConfig::firstOrNew(['workspace_id' => $wsId, 'provider' => OpenAiAdsClient::PROVIDER]);
        $creds = $cfg->creds();
        $creds['api_key'] = trim($data['api_key']);
        $cfg->setCreds($creds);
        $cfg->meta_json = array_merge((array) $cfg->meta_json, ['account' => $probe['data']]);
        $cfg->status = WaProviderConfig::STATUS_CONNECTED;
        $cfg->is_primary = false; // never a messaging sender
        $cfg->connected_at = now();
        $cfg->save();

        return redirect()->route('user.openai-ads.index')
            ->with('status', __('OpenAI Ads connected.'));
    }

    /** POST /openai-ads/verify — re-check the key + refresh the account snapshot. */
    public function verify(): RedirectResponse
    {
        $client = OpenAiAdsClient::forWorkspace($this->wsId());
        if (! $client) {
            return redirect()->route('user.openai-ads.connect')
                ->withErrors(['api_key' => __('Connect an API key first.')]);
        }
        $acc = $client->getAdAccount();
        if (! $acc['ok']) {
            return back()->withErrors(['api_key' => __('Verification failed: :err', ['err' => $acc['error']])]);
        }
        if ($config = OpenAiAdsClient::configFor($this->wsId())) {
            $config->meta_json = array_merge((array) $config->meta_json, ['account' => $acc['data']]);
            $config->status = WaProviderConfig::STATUS_CONNECTED;
            $config->save();
        }

        return back()->with('status', __('Verified — account is reachable.'));
    }

    /** GET /openai-ads/create — the campaign wizard. */
    public function create(): View|RedirectResponse
    {
        $config = OpenAiAdsClient::configFor($this->wsId());
        if (! OpenAiAdsClient::forWorkspace($this->wsId())) {
            return redirect()->route('user.openai-ads.connect');
        }

        return view('user.openai-ads.create', [
            'account'       => (array) ($config?->meta_json['account'] ?? []),
            // Conversion event settings the operator can pick for oCPC bidding.
            'eventSettings' => OpenAiAdsEventSetting::forWorkspace($this->wsId())
                ->whereNotNull('remote_id')->orderBy('name')->get(),
        ]);
    }

    // =================================================================
    // BUILD WITH AI (P5) — generate ad copy from a brief
    // =================================================================

    /** GET /openai-ads/api/ai-models — the AI models the copy generator can use. */
    public function apiAiModels(): JsonResponse
    {
        $rows = \DB::table('admin_ai_keys')
            ->where('is_active', true)
            ->whereNotIn('provider', ['elevenlabs'])
            ->orderBy('sort_order')
            ->get(['provider', 'name', 'default_model', 'extra_config']);

        $providerLabel = ['openai' => 'OpenAI', 'anthropic' => 'Anthropic', 'gemini' => 'Google', 'mistral' => 'Mistral'];

        $models = [];
        foreach ($rows as $r) {
            $label = $providerLabel[$r->provider] ?? ucfirst($r->provider);
            $default = (string) ($r->default_model ?? '');
            if ($default === '') $default = \App\Services\AiAgentService::fallbackModel($r->provider);
            if ($default === '') continue;
            $extra = json_decode((string) ($r->extra_config ?? '[]'), true) ?: [];
            $extraModels = is_array($extra['models'] ?? null) ? $extra['models'] : [];
            foreach (array_values(array_unique(array_merge([$default], $extraModels))) as $m) {
                $models[] = ['value' => $m, 'label' => $label . ' · ' . $m, 'provider' => $r->provider];
            }
        }

        // BYOK — the workspace's own active AI keys also appear (and are used).
        $ws = Auth::user()?->current_workspace_id ? \App\Models\Workspace::find(Auth::user()->current_workspace_id) : null;
        if ($ws) {
            $byokDefaults = [
                'openai'    => ['gpt-4o-mini', 'gpt-4o'],
                'anthropic' => ['claude-sonnet-4-6', 'claude-haiku-4-5-20251001'],
                'gemini'    => ['gemini-2.0-flash', 'gemini-1.5-pro'],
                'mistral'   => ['mistral-large-latest', 'mistral-small-latest'],
            ];
            $own = \App\Models\AiProviderKey::query()->where('workspace_id', $ws->id)->where('is_active', true)->pluck('provider')->all();
            foreach ($own as $prov) {
                $models = array_values(array_filter($models, fn ($mm) => $mm['provider'] !== $prov));
                $plabel = $providerLabel[$prov] ?? ucfirst($prov);
                foreach (($byokDefaults[$prov] ?? []) as $m) {
                    $models[] = ['value' => $m, 'label' => $plabel . ' (your key) · ' . $m, 'provider' => $prov];
                }
            }
        }

        return response()->json(['ok' => true, 'models' => $models]);
    }

    /**
     * POST /openai-ads/api/ai-generate — generate ready-to-fill OpenAI Ads copy
     * (campaign/ad-group/ad names + headline + body) from a brief. The wizard
     * pastes the response into its fields.
     */
    public function apiAiGenerate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'model'         => 'required|string|max:120',
            'provider'      => ['required', 'string', \Illuminate\Validation\Rule::in(\App\Services\AiAgentService::supportedProviders())],
            'business_name' => 'required|string|max:191',
            'product'       => 'nullable|string|max:255',
            'audience'      => 'nullable|string|max:500',
            'tone'          => 'nullable|string|max:60',
            'custom_prompt' => 'nullable|string|max:2000',
        ]);

        $workspace = Auth::user()?->current_workspace_id ? \App\Models\Workspace::find(Auth::user()->current_workspace_id) : null;

        $resolved = \App\Services\AiKeyResolver::resolve($workspace, $data['provider']);
        if (! $resolved['key']) {
            return response()->json(['ok' => false, 'error' => 'no_key', 'message' => __('This AI provider is not enabled yet.')], 422);
        }

        $systemPrompt = <<<'SYS'
You write advertising copy for OpenAI (ChatGPT) ads. Output STRICT JSON only —
no prose, no markdown, no code fences. Schema:

{
  "campaign_name": "<short, max 60>",
  "adgroup_name":  "<short, max 60>",
  "ad_name":       "<short, max 60>",
  "headline":      "<max 50 chars, attention-grabbing, no clickbait>",
  "body":          "<max 100 chars, persuasive, plain text, no emoji>"
}

Rules:
1. No emojis. Plain text only. Capitalise correctly (no ALL CAPS).
2. Headline must be at most 50 characters and hook fast.
3. Body must be at most 100 characters and describe the offer + a clear next step.
4. No exaggerated or policy-violating claims (health/finance/etc.).
5. Output ONLY the JSON object. No explanation. No code fences.
SYS;

        $lines = ['Business name: ' . $data['business_name']];
        if (! empty($data['product']))  $lines[] = 'Product / service: ' . $data['product'];
        if (! empty($data['audience'])) $lines[] = 'Target audience: ' . $data['audience'];
        if (! empty($data['tone']))     $lines[] = 'Tone: ' . $data['tone'];
        if (! empty($data['custom_prompt'])) { $lines[] = ''; $lines[] = 'Additional notes:'; $lines[] = $data['custom_prompt']; }

        $ai = app(\App\Services\AiAgentService::class);
        $raw = $ai->callProvider(
            provider: $data['provider'], model: $data['model'],
            workspaceId: (int) ($workspace?->id ?? 0),
            systemPrompt: $systemPrompt, userPrompt: implode("\n", $lines),
            maxTokens: 700, temperature: 0.7,
        );

        if (! $raw) {
            return response()->json(['ok' => false, 'error' => 'provider_failed', 'message' => $ai->lastProviderError() ?: __('The AI provider returned no content.')], 502);
        }

        $clean = trim($raw);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $clean, $m)) $clean = trim($m[1]);
        $tpl = json_decode($clean, true);
        if (! is_array($tpl)) {
            return response()->json(['ok' => false, 'error' => 'bad_json', 'message' => __('The model output was not valid JSON. Try again.')], 422);
        }

        // Hard caps matching the store() validation so the wizard never paints
        // something the controller would reject.
        return response()->json(['ok' => true, 'payload' => [
            'campaign_name' => mb_substr((string) ($tpl['campaign_name'] ?? ''), 0, 200),
            'adgroup_name'  => mb_substr((string) ($tpl['adgroup_name'] ?? ''), 0, 200),
            'ad_name'       => mb_substr((string) ($tpl['ad_name'] ?? ''), 0, 200),
            'headline'      => mb_substr((string) ($tpl['headline'] ?? ''), 0, 50),
            'body'          => mb_substr((string) ($tpl['body'] ?? ''), 0, 100),
        ]]);
    }

    // =================================================================
    // ANALYTICS (P4) — delivery insights
    // =================================================================

    /**
     * GET /openai-ads/analytics — account delivery insights, broken down by
     * campaign over a date range. Uses GET /ad_account/insights with
     * aggregation_level=campaign. Date range defaults to the last 30 days.
     */
    public function analytics(Request $request): View|RedirectResponse
    {
        $wsId = $this->wsId();
        $client = OpenAiAdsClient::forWorkspace($wsId);
        if (! $client) return redirect()->route('user.openai-ads.connect');

        $end   = $request->query('end') ?: now()->toDateString();
        $start = $request->query('start') ?: now()->subDays(29)->toDateString();
        // A time_ranges entry is a FLAT object: {type:'date_range', since, until,
        // timezone?}. since/until sit DIRECTLY next to type — they are NOT nested
        // under a `date_range` key (nesting it makes `date_range` an unsupported
        // field → 400 "only supports fields: since, until, timezone").
        $range = [json_encode(['type' => 'date_range', 'since' => $start, 'until' => $end])];

        // (1) Per-campaign breakdown for the table + bar chart.
        $byCampaign = $client->insights('ad_account', null, [
            'time_granularity'  => 'none',
            'aggregation_level' => 'campaign',
            'time_ranges'       => $range,
            'fields'            => ['impressions', 'clicks', 'spend', 'ctr', 'cpc', 'cpm'],
            'limit'             => 200,
        ]);
        $rows  = $byCampaign['ok'] ? (array) data_get($byCampaign['data'], 'data', []) : [];
        $error = $byCampaign['ok'] ? null : $byCampaign['error'];

        // (2) Daily account trend for the line chart.
        $daily = $client->insights('ad_account', null, [
            'time_granularity'  => 'daily',
            'aggregation_level' => 'ad_account',
            'time_ranges'       => $range,
            'fields'            => ['impressions', 'clicks', 'spend'],
            'limit'             => 400,
        ]);
        $trendRows = $daily['ok'] ? (array) data_get($daily['data'], 'data', []) : [];

        // Totals across the campaign rows for the KPI strip.
        $totals = ['impressions' => 0, 'clicks' => 0, 'spend' => 0.0];
        foreach ($rows as $r) {
            $totals['impressions'] += (int) data_get($r, 'impressions', 0);
            $totals['clicks']      += (int) data_get($r, 'clicks', 0);
            $totals['spend']       += (float) data_get($r, 'spend', 0);
        }
        $totals['ctr'] = $totals['impressions'] > 0 ? ($totals['clicks'] / $totals['impressions']) * 100 : 0.0;
        $totals['cpc'] = $totals['clicks'] > 0 ? ($totals['spend'] / $totals['clicks']) : 0.0;
        $totals['cpm'] = $totals['impressions'] > 0 ? ($totals['spend'] / $totals['impressions']) * 1000 : 0.0;

        // Chart payload for ApexCharts (read by user-openai-ads-analytics.js).
        $chartData = [
            'currency' => data_get(OpenAiAdsClient::configFor($wsId)?->meta_json, 'account.currency_code', ''),
            'trend' => [
                'labels'      => array_map(fn ($r) => (string) (data_get($r, 'readable_time') ?? data_get($r, 'start_time')), $trendRows),
                'spend'       => array_map(fn ($r) => round((float) data_get($r, 'spend', 0), 2), $trendRows),
                'clicks'      => array_map(fn ($r) => (int) data_get($r, 'clicks', 0), $trendRows),
                'impressions' => array_map(fn ($r) => (int) data_get($r, 'impressions', 0), $trendRows),
            ],
            'campaigns' => array_map(fn ($r) => [
                'name'   => (string) (data_get($r, 'campaign_name') ?? data_get($r, 'campaign_id') ?? '—'),
                'spend'  => round((float) data_get($r, 'spend', 0), 2),
                'clicks' => (int) data_get($r, 'clicks', 0),
            ], array_slice($rows, 0, 12)),
        ];

        return view('user.openai-ads.analytics', [
            'rows'      => $rows,
            'totals'    => $totals,
            'chartData' => $chartData,
            'start'     => $start,
            'end'       => $end,
            'account'   => (array) (OpenAiAdsClient::configFor($wsId)?->meta_json['account'] ?? []),
            'error'     => $error,
        ]);
    }

    // =================================================================
    // CONVERSIONS (P3) — pixels, Conversions API keys, event settings
    // =================================================================

    /** GET /openai-ads/conversions — the conversion-setup hub. */
    public function conversions(): View|RedirectResponse
    {
        $wsId = $this->wsId();
        if (! OpenAiAdsClient::forWorkspace($wsId)) {
            return redirect()->route('user.openai-ads.connect');
        }

        return view('user.openai-ads.conversions', [
            'pixels'        => OpenAiAdsPixel::forWorkspace($wsId)->orderByDesc('id')->get(),
            'eventSettings' => OpenAiAdsEventSetting::forWorkspace($wsId)->orderByDesc('id')->get(),
            'apiKeys'       => OpenAiAdsApiKey::forWorkspace($wsId)->orderByDesc('id')->get(),
            'account'       => (array) (OpenAiAdsClient::configFor($wsId)?->meta_json['account'] ?? []),
        ]);
    }

    /** POST /openai-ads/conversions/pixels — create a web pixel. */
    public function storePixel(Request $request): RedirectResponse
    {
        $wsId = $this->wsId();
        $client = OpenAiAdsClient::forWorkspace($wsId);
        if (! $client) return redirect()->route('user.openai-ads.connect');

        $data = $request->validate(['name' => ['required', 'string', 'min:3', 'max:1000']]);
        $r = $client->createPixel($data['name'], 'web');
        if (! $r['ok']) return back()->withErrors(['pixel' => $r['error']]);

        OpenAiAdsPixel::create([
            'workspace_id' => $wsId,
            'remote_id'    => data_get($r['data'], 'id'),        // clidsrc_
            'pixel_id'     => data_get($r['data'], 'pixel_id'),
            'name'         => $data['name'],
            'client_type'  => 'web',
            'meta_json'    => $r['data'],
        ]);

        return back()->with('status', __('Pixel created.'));
    }

    /**
     * POST /openai-ads/conversions/api-keys — create a Conversions API key. The
     * secret is returned ONCE: flash it for the operator to copy, store only a
     * masked hint (never the secret itself).
     */
    public function storeApiKey(Request $request): RedirectResponse
    {
        $wsId = $this->wsId();
        $client = OpenAiAdsClient::forWorkspace($wsId);
        if (! $client) return redirect()->route('user.openai-ads.connect');

        $data = $request->validate(['name' => ['required', 'string', 'min:3', 'max:1000']]);
        $r = $client->createConversionsApiKey($data['name']);
        if (! $r['ok']) return back()->withErrors(['api_key' => $r['error']]);

        $secret = (string) data_get($r['data'], 'api_key');
        OpenAiAdsApiKey::create([
            'workspace_id' => $wsId,
            'name'         => $data['name'],
            'masked'       => $secret !== '' ? '…' . substr($secret, -4) : null,
        ]);

        return back()
            ->with('status', __('API key created. Copy it now — it is shown only once.'))
            ->with('new_api_key', $secret);
    }

    /** POST /openai-ads/conversions/event-settings — define a conversion event. */
    public function storeEventSetting(Request $request): RedirectResponse
    {
        $wsId = $this->wsId();
        $client = OpenAiAdsClient::forWorkspace($wsId);
        if (! $client) return redirect()->route('user.openai-ads.connect');

        $data = $request->validate([
            'name'                    => ['required', 'string', 'min:3', 'max:1000'],
            'event_type'              => ['required', 'string', 'max:100'],
            'custom_event_name'       => ['nullable', 'string', 'max:100', 'required_if:event_type,custom'],
            'attribution_window_days' => ['required', 'integer', 'min:1', 'max:90'],
            'pixel_local_id'          => ['required', 'integer'],
        ]);

        $pixel = OpenAiAdsPixel::forWorkspace($wsId)->find($data['pixel_local_id']);
        if (! $pixel || ! $pixel->remote_id) {
            return back()->withErrors(['pixel_local_id' => __('Pick a pixel first.')]);
        }

        $body = [
            'name'                    => $data['name'],
            'event_type'              => $data['event_type'],
            'attribution_window_days' => (int) $data['attribution_window_days'],
            'source_ids'              => [$pixel->remote_id],  // exactly one source
        ];
        if ($data['event_type'] === 'custom' && ! empty($data['custom_event_name'])) {
            $body['custom_event_name'] = $data['custom_event_name'];
        }
        $r = $client->createEventSetting($body);
        if (! $r['ok']) return back()->withErrors(['event' => $r['error']]);

        OpenAiAdsEventSetting::create([
            'workspace_id'            => $wsId,
            'remote_id'               => data_get($r['data'], 'id'),  // ces_
            'name'                    => $data['name'],
            'event_type'              => $data['event_type'],
            'custom_event_name'       => $data['custom_event_name'] ?? null,
            'attribution_window_days' => (int) $data['attribution_window_days'],
            'source_ids'              => [$pixel->remote_id],
            'pixel_local_id'          => $pixel->id,
            'meta_json'               => $r['data'],
        ]);

        return back()->with('status', __('Conversion event created.'));
    }

    /**
     * POST /openai-ads — create the whole chain in one shot:
     *   createCampaign(paused) → createAdGroup(paused) → [upload creative] →
     *   createAd(paused). Everything is created PAUSED; the operator activates
     *   from the detail page (the API requires children active before a campaign
     *   can go live). Local mirror rows are written as each remote object is
     *   created, so a mid-chain API failure still leaves a usable record.
     */
    public function store(Request $request): RedirectResponse
    {
        $wsId = $this->wsId();
        $client = OpenAiAdsClient::forWorkspace($wsId);
        if (! $client) return redirect()->route('user.openai-ads.connect');

        $data = $request->validate([
            'name'               => ['required', 'string', 'min:3', 'max:1000'],
            'budget'             => ['required', 'numeric', 'min:1'],       // major units; min $1
            'bidding_type'       => ['required', 'in:impressions,clicks,conversions'],
            'conversion_event_setting_id' => ['nullable', 'string', 'max:64'],
            'adgroup_name'       => ['required', 'string', 'min:3', 'max:1000'],
            'billing_event_type' => ['required', 'in:impression,click'],
            'max_bid'            => ['required', 'numeric', 'min:0.000001'], // major units
            'creative_type'     => ['required', 'in:chat_card,product_ad_template'],
            'ad_name'            => ['required', 'string', 'min:3', 'max:1000'],
            'title'              => ['required', 'string', 'min:3', 'max:50'],
            'body'               => ['required', 'string', 'max:100'],
            'price'              => ['nullable', 'string', 'max:100'],
            'target_url'         => ['nullable', 'url', 'max:2048', 'required_if:creative_type,chat_card'],
            'image_url'          => ['nullable', 'url', 'max:2048', 'required_if:creative_type,chat_card'],
        ]);

        // 1) Campaign (paused).
        $campaignBody = [
            'name'         => $data['name'],
            'status'       => 'paused',
            'budget'       => ['lifetime_spend_limit_micros' => OpenAiAdsClient::toMicros($data['budget'])],
            'bidding_type' => $data['bidding_type'],
        ];
        if ($data['bidding_type'] === 'conversions' && ! empty($data['conversion_event_setting_id'])) {
            $campaignBody['conversion_event_setting_ids'] = [$data['conversion_event_setting_id']];
        }
        $c = $client->createCampaign($campaignBody);
        if (! $c['ok']) {
            return back()->withInput()->withErrors(['name' => __('Campaign create failed: :e', ['e' => $c['error']])]);
        }
        $campaign = OpenAiAdsCampaign::create([
            'workspace_id' => $wsId,
            'user_id'      => Auth::id(),
            'remote_id'    => data_get($c['data'], 'id'),
            'name'         => $data['name'],
            'status'       => data_get($c['data'], 'status', 'paused'),
            'bidding_type' => $data['bidding_type'],
            'budget_micros' => OpenAiAdsClient::toMicros($data['budget']),
            'conversion_event_setting_ids' => $campaignBody['conversion_event_setting_ids'] ?? null,
            'last_synced_at' => now(),
            'meta_json'    => $c['data'],
        ]);

        // 2) Ad group (paused).
        $ag = $client->createAdGroup([
            'campaign_id'    => $campaign->remote_id,
            'name'           => $data['adgroup_name'],
            'status'         => 'paused',
            'bidding_config' => [
                'billing_event_type' => $data['billing_event_type'],
                'max_bid_micros'     => OpenAiAdsClient::toMicros($data['max_bid']),
            ],
        ]);
        if (! $ag['ok']) {
            return redirect()->route('user.openai-ads.show', $campaign->id)
                ->withErrors(['adgroup_name' => __('Campaign created, but ad group failed: :e', ['e' => $ag['error']])]);
        }
        $adGroup = OpenAiAdsAdGroup::create([
            'workspace_id' => $wsId,
            'campaign_id'  => $campaign->id,
            'remote_id'    => data_get($ag['data'], 'id'),
            'name'         => $data['adgroup_name'],
            'status'       => data_get($ag['data'], 'status', 'paused'),
            'billing_event_type' => $data['billing_event_type'],
            'max_bid_micros'     => OpenAiAdsClient::toMicros($data['max_bid']),
            'meta_json'    => $ag['data'],
        ]);

        // 3) Creative upload (chat_card needs a file_id).
        $fileId = null;
        if (! empty($data['image_url'])) {
            $up = $client->upload($data['image_url'], 'ad_creative');
            if ($up['ok']) {
                $fileId = data_get($up['data'], 'file_id') ?? data_get($up['data'], 'id');
            } elseif ($data['creative_type'] === 'chat_card') {
                return redirect()->route('user.openai-ads.show', $campaign->id)
                    ->withErrors(['image_url' => __('Creative upload failed: :e', ['e' => $up['error']])]);
            }
        }

        // 4) Ad (paused).
        $creative = array_filter([
            'type'       => $data['creative_type'],
            'title'      => $data['title'],
            'body'       => $data['body'],
            'price'      => $data['price'] ?? null,
            'target_url' => $data['target_url'] ?? null,
            'file_id'    => $fileId,
        ], fn ($v) => $v !== null && $v !== '');

        $ad = $client->createAd([
            'ad_group_id' => $adGroup->remote_id,
            'name'        => $data['ad_name'],
            'status'      => 'paused',
            'creative'    => $creative,
        ]);
        if (! $ad['ok']) {
            return redirect()->route('user.openai-ads.show', $campaign->id)
                ->withErrors(['ad_name' => __('Campaign + ad group created, but ad failed: :e', ['e' => $ad['error']])]);
        }
        OpenAiAdsAd::create([
            'workspace_id' => $wsId,
            'ad_group_id'  => $adGroup->id,
            'remote_id'    => data_get($ad['data'], 'id'),
            'name'         => $data['ad_name'],
            'status'       => data_get($ad['data'], 'status', 'paused'),
            'review_status' => data_get($ad['data'], 'review_status'),
            'creative_type' => $data['creative_type'],
            'title'        => $data['title'],
            'body'         => $data['body'],
            'price'        => $data['price'] ?? null,
            'target_url'   => $data['target_url'] ?? null,
            'file_id'      => $fileId,
            'meta_json'    => $ad['data'],
        ]);

        return redirect()->route('user.openai-ads.show', $campaign->id)
            ->with('status', __('Campaign created (paused). Review it, then activate to go live.'));
    }

    /** GET /openai-ads/{id} — local campaign detail + its ad groups/ads. */
    public function show(int $id): View|RedirectResponse
    {
        $campaign = OpenAiAdsCampaign::forWorkspace($this->wsId())
            ->with(['adGroups.ads'])->find($id);
        if (! $campaign) return redirect()->route('user.openai-ads.index');

        return view('user.openai-ads.show', [
            'campaign' => $campaign,
            'account'  => (array) (OpenAiAdsClient::configFor($this->wsId())?->meta_json['account'] ?? []),
        ]);
    }

    /**
     * POST /openai-ads/{id}/activate — take the campaign live. The API requires
     * children active first, so activate ads → ad groups → campaign, bottom-up.
     */
    public function activate(int $id): RedirectResponse
    {
        $campaign = OpenAiAdsCampaign::forWorkspace($this->wsId())->with(['adGroups.ads'])->find($id);
        $client = OpenAiAdsClient::forWorkspace($this->wsId());
        if (! $campaign || ! $client) return redirect()->route('user.openai-ads.index');

        $errors = [];
        DB::transaction(function () use ($campaign, $client, &$errors) {
            foreach ($campaign->adGroups as $ag) {
                foreach ($ag->ads as $ad) {
                    if (! $ad->remote_id) continue;
                    $r = $client->activateAd($ad->remote_id);
                    if ($r['ok']) $ad->update(['status' => 'active']);
                    else $errors[] = "ad {$ad->name}: {$r['error']}";
                }
                if ($ag->remote_id) {
                    $r = $client->activateAdGroup($ag->remote_id);
                    if ($r['ok']) $ag->update(['status' => 'active']);
                    else $errors[] = "ad group {$ag->name}: {$r['error']}";
                }
            }
            if ($campaign->remote_id && empty($errors)) {
                $r = $client->activateCampaign($campaign->remote_id);
                if ($r['ok']) $campaign->update(['status' => 'active']);
                else $errors[] = "campaign: {$r['error']}";
            }
        });

        if ($errors) {
            return back()->withErrors(['activate' => __('Could not fully activate: :e', ['e' => implode('; ', $errors)])]);
        }
        return back()->with('status', __('Campaign is live.'));
    }

    /** POST /openai-ads/{id}/pause — pause the campaign. */
    public function pause(int $id): RedirectResponse
    {
        $campaign = OpenAiAdsCampaign::forWorkspace($this->wsId())->find($id);
        $client = OpenAiAdsClient::forWorkspace($this->wsId());
        if (! $campaign || ! $client) return redirect()->route('user.openai-ads.index');

        if ($campaign->remote_id) {
            $r = $client->pauseCampaign($campaign->remote_id);
            if (! $r['ok']) return back()->withErrors(['pause' => $r['error']]);
        }
        $campaign->update(['status' => 'paused']);
        return back()->with('status', __('Campaign paused.'));
    }

    /** DELETE /openai-ads/keys — disconnect (remove the stored key). */
    public function disconnect(): RedirectResponse
    {
        WaProviderConfig::query()->forWorkspace($this->wsId())
            ->where('provider', OpenAiAdsClient::PROVIDER)->delete();

        return redirect()->route('user.openai-ads.connect')
            ->with('status', __('OpenAI Ads disconnected.'));
    }
}
