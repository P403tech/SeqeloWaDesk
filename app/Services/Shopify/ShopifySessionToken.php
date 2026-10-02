<?php

namespace App\Services\Shopify;

use Illuminate\Support\Facades\Log;

/**
 * Verifier for Shopify session tokens (App Bridge "ID tokens").
 *
 * An embedded app must not trust its iframe cookie to prove who is calling —
 * Shopify requires every authenticated request from the embed to carry
 * `Authorization: Bearer <jwt>`, and the backend to verify that JWT itself.
 *
 * Per Shopify's session-token spec the token is signed HS256 with the app's
 * CLIENT SECRET, and the backend must check, in this order:
 *
 *   1. signature (HS256 over "header.payload" using the client secret)
 *   2. exp  — must be in the future
 *   3. nbf  — must be in the past
 *   4. aud  — must equal the app's client id
 *   5. iss / dest — their HOSTNAMES must match each other
 *
 * `sub`, `sid`, `jti`, `iat` are identity claims: readable, never validated.
 *
 * A sample payload (from Shopify's docs) looks like:
 *   {
 *     "iss":  "https://exampleshop.myshopify.com/admin",
 *     "dest": "https://exampleshop.myshopify.com",
 *     "aud":  "client-id-123",
 *     "sub":  "42",
 *     "exp":  1591765058, "nbf": 1591764998, "iat": 1591764998,
 *     "jti":  "f8912129-…", "sid": "aaea182f…"
 *   }
 *
 * Everything here is constant-time where it compares secrets, and returns null
 * rather than throwing so the caller can answer 401 without leaking which check
 * failed.
 */
class ShopifySessionToken
{
    /** Clock skew allowed on exp/nbf, in seconds. Shopify tokens live ~60s. */
    private const LEEWAY = 5;

    /**
     * Verify a raw JWT. Returns the decoded payload on success, null on any
     * failure (bad signature, expired, wrong audience, mismatched shop).
     */
    public static function verify(string $jwt, ?string $clientId = null, ?string $clientSecret = null): ?array
    {
        $svc          = app(ShopifyService::class);
        $clientId     = $clientId     ?? $svc->clientId();
        $clientSecret = $clientSecret ?? $svc->clientSecret();

        if ($jwt === '' || $clientId === '' || $clientSecret === '') {
            return null;
        }

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        [$h64, $p64, $s64] = $parts;

        $header = json_decode(self::b64decode($h64) ?? '', true);
        if (! is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
            // Reject anything that is not HS256 — notably "none", which would
            // otherwise let an attacker hand us an unsigned token.
            return null;
        }

        // 1. Signature. hash_equals so a wrong token cannot be probed by timing.
        $expected = hash_hmac('sha256', $h64 . '.' . $p64, $clientSecret, true);
        $given    = self::b64decode($s64);
        if ($given === null || ! hash_equals($expected, $given)) {
            return null;
        }

        $payload = json_decode(self::b64decode($p64) ?? '', true);
        if (! is_array($payload)) {
            return null;
        }

        $now = time();

        // 2. exp must be in the future.
        if (! isset($payload['exp']) || ($now - self::LEEWAY) >= (int) $payload['exp']) {
            return null;
        }

        // 3. nbf must be in the past.
        if (isset($payload['nbf']) && ($now + self::LEEWAY) < (int) $payload['nbf']) {
            return null;
        }

        // 4. aud must be this app's client id.
        if (! hash_equals($clientId, (string) ($payload['aud'] ?? ''))) {
            return null;
        }

        // 5. iss and dest must point at the SAME shop. `iss` carries the /admin
        //    path, so compare hostnames rather than whole URLs.
        $issHost  = parse_url((string) ($payload['iss'] ?? ''), PHP_URL_HOST);
        $destHost = parse_url((string) ($payload['dest'] ?? ''), PHP_URL_HOST);
        if (! $issHost || ! $destHost || strcasecmp($issHost, $destHost) !== 0) {
            return null;
        }

        return $payload;
    }

    /** The shop domain the token was issued for, e.g. "acme.myshopify.com". */
    public static function shopFrom(array $payload): string
    {
        return (string) (parse_url((string) ($payload['dest'] ?? ''), PHP_URL_HOST) ?: '');
    }

    /** base64url → raw bytes; null when the segment is not valid base64url. */
    private static function b64decode(string $seg): ?string
    {
        $b64 = strtr($seg, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode($b64, true);

        return $out === false ? null : $out;
    }
}
