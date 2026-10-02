<?php

namespace App\Http\Controllers;

use App\Enums\WaProvider;
use App\Models\SystemSetting;
use App\Models\WaProviderConfig;
use App\Models\WaStorefront;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WABA Embedded Signup callback — front-end posts the OAuth `code`
 * + the `phone_number_id` + `waba_id` + `business_id` it received
 * from FB.login. We exchange the code for an access token, subscribe
 * Meta's webhook, register the phone, link a catalog, and write the
 * wa_provider_configs row marked 'connected'.
 */
class WaConnectWabaController extends Controller
{
    public function complete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code'             => 'required|string|max:1024',
            // NULLABLE — a coexistence onboard often returns only waba_id in the
            // postMessage; the Cloud-API registration of the existing Business
            // App number is asynchronous, so phone_number_id is resolved below
            // from the WABA's phone_numbers edge when it is absent.
            'phone_number_id'  => 'nullable|string|max:64',
            'waba_id'          => 'required|string|max:64',
            'business_id'      => 'nullable|string|max:64',
            // Coexistence onboard (number stays live on the WhatsApp Business
            // app). The front-end sets this when the admin's waba_coexistence
            // toggle launched the "whatsapp_business_app_onboarding" flow.
            'coexistence'      => 'nullable|boolean',
        ]);

        // A coexistence number is SHARED with the WhatsApp Business app. The
        // /register step below migrates a number fully onto the Cloud API —
        // running it on a coexistence number would kick it OFF the app and
        // break the whole point. So we skip registration for coexistence and
        // let the number keep running on both.
        $coexistence = (bool) ($data['coexistence'] ?? false);
        // Resolved phone_number_id — starts from the payload, filled from the
        // WABA edge below when the coexistence flow omitted it.
        $pnid   = (string) ($data['phone_number_id'] ?? '');
        $wabaId = (string) $data['waba_id'];

        $appId     = (string) SystemSetting::get('waba_app_id', '');
        $appSecret = (string) SystemSetting::get('waba_app_secret', '');
        if ($appId === '' || $appSecret === '') {
            return response()->json(['ok' => false, 'message' => 'Meta App credentials not configured.'], 422);
        }

        // Graph API version — single source of truth (admin setting), so this
        // Embedded-Signup probe tracks the same version as every other Meta
        // call (default v23.0). It was hardcoded to v22.0 and drifted behind.
        $gv = (string) SystemSetting::get('waba_graph_api_version', 'v23.0');

        // 1. Exchange code → access_token
        try {
            $tokenRes = Http::timeout(10)->get("https://graph.facebook.com/{$gv}/oauth/access_token", [
                'client_id'     => $appId,
                'client_secret' => $appSecret,
                'code'          => $data['code'],
            ]);
            if (!$tokenRes->successful() || !$tokenRes->json('access_token')) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Could not exchange code: ' . ($tokenRes->json('error.message') ?? $tokenRes->status()),
                ], 422);
            }
            $accessToken = $tokenRes->json('access_token');
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Token exchange failed: ' . $e->getMessage()], 500);
        }

        $errors = [];

        // 2. Subscribe to webhook events on the WABA
        try {
            $sub = Http::withToken($accessToken)
                ->timeout(10)
                ->post("https://graph.facebook.com/{$gv}/{$data['waba_id']}/subscribed_apps");
            if (!$sub->successful()) {
                $errors['subscribe'] = $sub->json('error.message') ?? 'unknown';
            }
        } catch (\Throwable $e) {
            $errors['subscribe'] = $e->getMessage();
        }

        // 2b. Resolve the phone_number_id when the (coexistence) postMessage
        //     returned only the waba_id. The existing Business App number is
        //     registered on Cloud API asynchronously, so pull it from the WABA's
        //     phone_numbers edge and flag coexistence from platform_type. Mirrors
        //     DevicesController::wabaConnectEmbedded so both connect surfaces
        //     behave identically.
        if ($pnid === '') {
            try {
                $edge = Http::withToken($accessToken)
                    ->timeout(10)
                    ->get("https://graph.facebook.com/{$gv}/{$wabaId}/phone_numbers", [
                        'fields' => 'id,display_phone_number,platform_type,verified_name',
                    ]);
                $rows = (array) ($edge->json('data') ?? []);
                // Meta's current docs report "COEXISTENCE"; older payloads use
                // "SMB_APP". Prefer a Business App number, else the first row.
                $coexTypes = ['COEXISTENCE', 'SMB_APP'];
                $pick = null;
                foreach ($rows as $r) {
                    if (in_array($r['platform_type'] ?? '', $coexTypes, true)) { $pick = $r; break; }
                }
                $pick = $pick ?: ($rows[0] ?? null);
                if ($pick && !empty($pick['id'])) {
                    $pnid = (string) $pick['id'];
                    $coexistence = $coexistence || in_array($pick['platform_type'] ?? '', $coexTypes, true);
                }
            } catch (\Throwable $e) {
                \Log::warning('[COEX-STORE] phone_numbers lookup threw', ['waba_id' => $wabaId, 'error' => $e->getMessage()]);
            }
        }

        // The number can still be genuinely absent if Meta has not finished the
        // async Cloud-API registration of the Business App number. Ask the user
        // to retry shortly rather than failing opaquely.
        if ($pnid === '') {
            \Log::warning('[COEX-STORE] no phone number on WABA yet', ['waba_id' => $wabaId, 'coexistence' => $coexistence]);
            return response()->json([
                'ok'      => false,
                'message' => __('Meta has not finished preparing this number yet. Please wait a minute and try Connect again.'),
            ], 422);
        }

        // 3. Register the phone number on Cloud API — SKIPPED for coexistence
        //    (registering would migrate the number fully onto the API and
        //    remove it from the WhatsApp Business app). Coexistence numbers
        //    are already usable for sending the moment subscribed_apps lands.
        $pin = null;
        if (!$coexistence) {
            $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            try {
                $reg = Http::withToken($accessToken)
                    ->timeout(10)
                    ->post("https://graph.facebook.com/{$gv}/{$pnid}/register", [
                        'messaging_product' => 'whatsapp',
                        'pin'               => $pin,
                    ]);
                if (!$reg->successful()) {
                    $errors['register'] = $reg->json('error.message') ?? 'unknown';
                }
            } catch (\Throwable $e) {
                $errors['register'] = $e->getMessage();
            }
        }

        // 4. Pull phone display number for our records
        $displayPhone = null;
        try {
            $info = Http::withToken($accessToken)
                ->timeout(10)
                ->get("https://graph.facebook.com/{$gv}/{$pnid}", [
                    'fields' => 'display_phone_number,verified_name',
                ]);
            $displayPhone = $info->json('display_phone_number');
        } catch (\Throwable $e) { /* optional */ }

        // 5. Auto-create or link a catalog (best effort)
        $catalogId = null;
        try {
            // List existing catalogs first; reuse if already linked
            $list = Http::withToken($accessToken)
                ->timeout(10)
                ->get("https://graph.facebook.com/{$gv}/{$data['waba_id']}/product_catalogs");
            if ($list->successful() && !empty($list->json('data.0.id'))) {
                $catalogId = $list->json('data.0.id');
            } elseif (!empty($data['business_id'])) {
                $create = Http::withToken($accessToken)
                    ->timeout(10)
                    ->post("https://graph.facebook.com/{$gv}/{$data['business_id']}/owned_product_catalogs", [
                        'name'     => \App\Models\SystemSetting::get('app_name', config('app.name', 'WaDesk')) . ' auto · ' . now()->format('Y-m-d'),
                        'vertical' => 'commerce',
                    ]);
                if ($create->successful()) {
                    $catalogId = $create->json('id');
                    Http::withToken($accessToken)
                        ->timeout(10)
                        ->post("https://graph.facebook.com/{$gv}/{$data['waba_id']}/product_catalogs", [
                            'catalog_id' => $catalogId,
                        ]);
                }
            }
        } catch (\Throwable $e) {
            $errors['catalog'] = $e->getMessage();
        }

        // 6. Persist
        $user = Auth::user();
        $workspaceId = $user->current_workspace_id;
        if (!$workspaceId) {
            return response()->json(['ok' => false, 'message' => 'No active workspace.'], 422);
        }

        // One WABA number = one workspace, platform-wide. Meta delivers a
        // phone_number_id's webhooks to a single app, and inbound routing
        // resolves the workspace FROM that id — so a second workspace claiming
        // it would make routing ambiguous and steal the first one's messages.
        if (\App\Support\ChannelClaim::heldElsewhere(
            WaProviderConfig::class,
            'meta_json->phone_number_id',
            $pnid,
            (int) $workspaceId
        )) {
            return response()->json([
                'ok'      => false,
                'message' => \App\Support\ChannelClaim::takenMessage(__('WhatsApp number')),
            ], 422);
        }

        // Multi-engine + multi-WABA: match an EXISTING WABA row by its Meta
        // phone_number_id (the account's true identity) so reconnecting the
        // same number updates it, connecting a NEW WABA number adds its own
        // row, and a Baileys/Twilio row for this workspace is never clobbered.
        $config = WaProviderConfig::query()
            ->where('workspace_id', $workspaceId)
            ->where('provider', WaProvider::Waba->value)
            ->where('meta_json->phone_number_id', $pnid)
            ->first()
            ?? new WaProviderConfig([
                'workspace_id' => $workspaceId,
                'provider'     => WaProvider::Waba->value,
            ]);
        $config->fill([
            'provider'      => WaProvider::Waba->value,
            'status'        => empty($errors) ? WaProviderConfig::STATUS_CONNECTED : WaProviderConfig::STATUS_FAILED,
            'phone_number'  => $displayPhone,
            'display_label' => 'WABA · ' . ($displayPhone ?: $pnid),
            'connected_at'  => now(),
            'is_primary'    => true,
            'meta_json'     => array_filter([
                'waba_id'         => $wabaId,
                'phone_number_id' => $pnid,
                'business_id'     => $data['business_id'] ?? null,
                'catalog_id'      => $catalogId,
                'coexistence'     => $coexistence ?: null, // badge + skip-register marker
                'errors'          => $errors ?: null,
            ]),
        ]);
        $config->setCreds([
            'access_token'         => $accessToken,
            'phone_number_id'      => $pnid,
            'waba_id'              => $wabaId,
            'business_id'          => $data['business_id'] ?? null,
            'register_pin'         => $pin,
            'catalog_id'           => $catalogId,
            'webhook_verify_token' => SystemSetting::get('waba_webhook_verify_token', ''),
        ]);
        $config->save();

        // Demote any other provider rows for this workspace so
        // WorkspaceEngine::for() resolves to WABA immediately. Same
        // reasoning as the Twilio/Baileys saves — a previous row marked
        // primary would otherwise keep the old engine sticky.
        WaProviderConfig::where('workspace_id', $workspaceId)
            ->where('id', '!=', $config->id)
            ->update(['is_primary' => false]);
        \App\Services\WorkspaceEngine::flush();

        WaStorefront::firstOrCreate(['workspace_id' => $workspaceId], ['theme_key' => WaStorefront::DEFAULT_THEME]);

        return response()->json([
            'ok'         => empty($errors),
            'errors'     => $errors,
            'phone'      => $displayPhone,
            'catalog_id' => $catalogId,
            // After connecting a channel, land on /devices (where the new number
            // shows), not the storefront.
            'redirect'   => url('/devices'),
        ]);
    }
}
