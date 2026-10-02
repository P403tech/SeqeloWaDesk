<?php

namespace App\Http\Controllers;

use App\Models\WaMerchantGateway;
use App\Services\Payment\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * /store/payments — the MERCHANT configures their OWN payment gateway keys so
 * CUSTOMER checkouts (native in-chat checkout) charge INTO the merchant's account,
 * across any of the ~32 gateways. Writes wa_merchant_gateways (encrypted creds).
 * Credentials are a universal key/value set so every driver is supported without a
 * per-gateway field map — the merchant pastes exactly the keys the gateway docs
 * name (e.g. Stripe: secret_key + webhook_secret; Razorpay: key_id + key_secret).
 */
class MerchantGatewaysController extends Controller
{
    /**
     * The gateway-KEYS UI moved to Settings → Payment gateways (a single home
     * shared by store checkout AND appointment booking). Old links/bookmarks to
     * /store/gateways redirect there. The save/toggle/destroy endpoints below
     * are unchanged — the Settings form still posts to them.
     */
    public function index(Request $request): RedirectResponse
    {
        return redirect()->to(route('user.settings') . '?tab=payments');
    }

    public function save(Request $request, string $slug): RedirectResponse|JsonResponse
    {
        $wsId = (int) $request->user()->current_workspace_id;
        $slug = strtolower(preg_replace('/[^a-z0-9_-]/i', '', $slug));
        abort_unless(isset(PaymentGatewayManager::GATEWAY_META[$slug]), 404);

        $request->validate([
            'creds'     => 'nullable|array',        // labelled fields: creds[key] = value
            'creds.*'   => 'nullable|string|max:4000',
            'creds_raw' => 'nullable|string|max:8000', // legacy key:value textarea fallback
            'mode'      => ['nullable', 'in:live,test'],
            'active'    => 'nullable|boolean',
        ]);

        $row = WaMerchantGateway::firstOrNew([
            'workspace_id' => $wsId, 'storefront_id' => 0, 'slug' => $slug,
        ]);
        $row->mode   = $request->input('mode') ?: ($row->mode ?: 'live');
        $row->active = $request->boolean('active', $row->exists ? (bool) $row->active : true);

        // Merge over existing creds so a blank field KEEPS its stored value (the
        // merchant can update just the webhook secret without re-typing keys).
        $creds = $row->exists ? $row->getDecryptedCredentials() : [];
        foreach ((array) $request->input('creds', []) as $k => $v) {
            $v = trim((string) $v);
            if ($v !== '') $creds[trim((string) $k)] = $v;
        }
        // Legacy textarea (any gateway with no declared fields).
        foreach ($this->parseCreds((string) $request->input('creds_raw', '')) as $k => $v) {
            $creds[$k] = $v;
        }
        if (!empty($creds)) {
            $row->setEncryptedCredentials($creds);
        }
        $row->save();

        return $this->respond($request, ucfirst($slug) . ' payment settings saved.');
    }

    public function toggle(Request $request, string $slug): RedirectResponse|JsonResponse
    {
        $wsId = (int) $request->user()->current_workspace_id;
        $slug = strtolower(preg_replace('/[^a-z0-9_-]/i', '', $slug));
        $row  = WaMerchantGateway::forWorkspace($wsId)->where('storefront_id', 0)->where('slug', $slug)->first();
        abort_unless($row, 404);
        $row->forceFill(['active' => !$row->active])->save();

        return $this->respond($request, $row->active ? 'Enabled.' : 'Disabled.');
    }

    public function destroy(Request $request, string $slug): RedirectResponse|JsonResponse
    {
        $wsId = (int) $request->user()->current_workspace_id;
        $slug = strtolower(preg_replace('/[^a-z0-9_-]/i', '', $slug));
        WaMerchantGateway::forWorkspace($wsId)->where('storefront_id', 0)->where('slug', $slug)->delete();

        return $this->respond($request, 'Removed.');
    }

    /** "key: value" / "key = value" lines → creds array. Empty lines ignored. */
    private function parseCreds(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || (!str_contains($line, ':') && !str_contains($line, '='))) continue;
            $parts = preg_split('/[:=]/', $line, 2);
            // Strip surrounding quotes/space a merchant might paste from a JSON or
            // env line — on BOTH the key and the value (e.g. "public_key": "pk_x").
            $k = trim(trim($parts[0] ?? ''), "\"' ");
            $v = trim(trim($parts[1] ?? ''), "\"' ,");
            if ($k !== '') $out[$k] = $v;
        }
        return $out;
    }

    private function respond(Request $request, string $message): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => true, 'message' => $message]);
        }
        return back()->with('status', $message);
    }
}
