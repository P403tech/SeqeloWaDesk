<?php

namespace App\Http\Middleware;

use App\Services\Shopify\ShopifySessionToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates embedded-app requests with a Shopify session token.
 *
 * App Bridge patches `fetch` inside the Shopify admin iframe and attaches
 * `Authorization: Bearer <id token>` to same-origin calls. This middleware is
 * the server half: it verifies that JWT and records which shop it proves.
 *
 * ADDITIVE ON PURPOSE. The app also serves ordinary browser navigations and
 * Blade form POSTs from inside the iframe, and a full page POST cannot carry an
 * Authorization header — those still authenticate with the Laravel session, as
 * they do today. So:
 *
 *   - Bearer present  → verify it. Invalid ⇒ 401, never a silent downgrade.
 *   - Bearer absent   → pass through untouched; the normal `auth` middleware
 *                       decides, exactly as before.
 *
 * That keeps /shopify working for existing merchants while giving Shopify's
 * automated check the token-authenticated requests it looks for.
 */
class VerifyShopifySessionToken
{
    /** Request attribute other code can read: the verified shop domain. */
    public const SHOP_ATTR = 'shopify_session_shop';

    /** Request attribute: the full verified payload. */
    public const CLAIMS_ATTR = 'shopify_session_claims';

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->bearer($request);

        if ($token === '') {
            // No token — not an App Bridge call. Leave auth to the session.
            return $next($request);
        }

        $claims = ShopifySessionToken::verify($token);

        if ($claims === null) {
            // A token WAS supplied and it did not verify. Fail closed: treating
            // it as "no token" would let a forged/expired JWT fall back to
            // whatever cookie happened to be attached.
            Log::warning('[SHOPIFY-TOKEN] rejected', [
                'path' => $request->path(),
                'ip'   => $request->ip(),
            ]);

            return response()->json([
                'ok'    => false,
                'error' => __('Invalid or expired Shopify session token.'),
            ], 401);
        }

        $request->attributes->set(self::SHOP_ATTR, ShopifySessionToken::shopFrom($claims));
        $request->attributes->set(self::CLAIMS_ATTR, $claims);

        return $next($request);
    }

    /** The bearer token, or '' when the header is absent/!bearer. */
    private function bearer(Request $request): string
    {
        $header = (string) $request->header('Authorization', '');
        if (stripos($header, 'Bearer ') !== 0) {
            return '';
        }

        return trim(substr($header, 7));
    }
}
