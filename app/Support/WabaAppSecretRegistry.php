<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;

/**
 * Meta `appsecret_proof` — sent ONLY when Meta asks for it.
 *
 * Every Graph API call goes out exactly as it always did: WITHOUT a proof. Most
 * Meta apps don't require one, and attaching a proof signed with the wrong app's
 * secret makes Meta reject an otherwise-valid call ("Invalid appsecret_proof
 * provided in the API argument", #100) — so a proof is never sent unasked.
 *
 * Only when Meta answers "API calls from the server require an appsecret_proof
 * argument" (the app has "Require app secret" ON) is the SAME call retried with
 * a proof = HMAC-SHA256(access_token, app_secret), trying every App Secret the
 * install knows for that number in trust order: the connection's own secret,
 * the workspace's own Meta app, the platform WhatsApp app, the platform
 * Facebook app. The secret Meta accepts is remembered for the token (shared
 * cache), so later calls attach that proof up front — one request, no retry.
 * If that remembered secret is ever rejected (App Secret reset), it is
 * forgotten and the call starts over from "no proof".
 *
 * Requests that already carry a proof (the Facebook client signs its own) and
 * app access tokens (`{id}|{secret}`) are never touched.
 */
class WabaAppSecretRegistry
{
    /** @var array<string,array{secret:string,ws:int}> sha1(token) => the connection's secret + workspace */
    private static array $map = [];

    /** @var array<string,array<string,string>> per-process memo: sha1(token) => [source => secret] */
    private static array $candidates = [];

    /** @var array<string,string> per-process memo: sha1(token) => accepted source ('' = none known) */
    private static array $known = [];

    /** Called from WaProviderConfig::creds() — which number/workspace this token belongs to. */
    public static function remember(string $token, string $appSecret, int $workspaceId = 0): void
    {
        $token = trim($token);
        if ($token === '' || str_contains($token, '|')) return;
        $key = sha1($token);
        $new = ['secret' => trim($appSecret), 'ws' => $workspaceId];
        if ((self::$map[$key] ?? null) !== $new) {
            self::$map[$key] = $new;
            unset(self::$candidates[$key]);
        }
    }

    /**
     * Every App Secret that could have issued this token, in trust order,
     * de-duplicated. @return array<string,string> source => secret
     */
    public static function candidates(string $token): array
    {
        $key = sha1(trim($token));
        if (isset(self::$candidates[$key])) return self::$candidates[$key];

        $info = self::$map[$key] ?? ['secret' => '', 'ws' => 0];
        $out  = [];
        $add  = function (string $source, $secret) use (&$out) {
            $secret = trim((string) $secret);
            if ($secret !== '' && ! in_array($secret, $out, true)) $out[$source] = $secret;
        };
        $add('connection', $info['secret']);
        if ($info['ws'] > 0) {
            try { $own = \App\Models\Workspace::find($info['ws'])?->ownMetaApp(); $add('workspace', $own['secret'] ?? ''); } catch (\Throwable $e) {}
        }
        try { $add('platform_waba', \App\Models\SystemSetting::get('waba_app_secret', '')); } catch (\Throwable $e) {}
        try { $add('platform_fb', \App\Models\SystemSetting::get('fb_app_secret', '')); } catch (\Throwable $e) {}

        return self::$candidates[$key] = $out;
    }

    /** The proof to attach UP FRONT — only for a token Meta already told us requires one. */
    public static function knownProof(string $token): string
    {
        $source = self::knownSource($token);
        if ($source === '') return '';
        $secret = self::candidates($token)[$source] ?? '';
        return $secret !== '' ? hash_hmac('sha256', trim($token), $secret) : '';
    }

    /** Guzzle middleware registered globally (AppServiceProvider) for every Http:: call, pools included. */
    public static function middleware(): callable
    {
        return function (callable $handler) {
            return function (RequestInterface $request, array $options) use ($handler) {
                try {
                    $uri = $request->getUri();
                    if (! str_contains($uri->getHost(), 'graph.facebook.com')) return $handler($request, $options);
                    if (str_contains($uri->getQuery(), 'appsecret_proof=')) return $handler($request, $options);
                    $token = self::tokenOf($request);
                    if ($token === '' || str_contains($token, '|')) return $handler($request, $options);
                    $known = self::knownProof($token);
                } catch (\Throwable $e) {
                    return $handler($request, $options);
                }

                $first = $known !== '' ? self::withProof($request, $known) : $request;

                return $handler($first, $options)->then(
                    function ($response) use ($handler, $request, $options, $token, $known) {
                        try {
                            if ($response->getStatusCode() < 400) return $response;
                            $text = self::bodyText($response);

                            // Remembered secret no longer accepted (App Secret was reset):
                            // forget it and start over exactly like a fresh token.
                            if ($known !== '' && self::isInvalidProof($text)) {
                                self::forgetKnown($token);
                                return $handler($request, $options)->then(
                                    fn ($plain) => self::isRequiresProof(self::bodyText($plain)) && ! self::noMatchRecently($token)
                                        ? self::discover($handler, $request, $options, $token, $plain)
                                        : $plain
                                );
                            }

                            // Meta ASKED for a proof — now (and only now) provide one.
                            // (Skipped for 10 min when no saved secret matched, so a
                            // misconfigured number fails fast instead of retrying
                            // every candidate on every send.)
                            if ($known === '' && self::isRequiresProof($text)) {
                                return self::noMatchRecently($token)
                                    ? $response
                                    : self::discover($handler, $request, $options, $token, $response);
                            }
                            return $response;
                        } catch (\Throwable $e) {
                            return $response;
                        }
                    }
                );
            };
        };
    }

    /**
     * Retry the call with each candidate secret's proof until Meta accepts one.
     * A response that is not "Invalid appsecret_proof" (success, or any other
     * Meta error) means the proof was accepted → remember that secret.
     */
    private static function discover(callable $handler, RequestInterface $request, array $options, string $token, $lastResponse)
    {
        $try = function (array $left, $last) use (&$try, $handler, $request, $options, $token) {
            if (empty($left)) {
                self::markNoMatch($token);
                Log::warning('[META] this app requires appsecret_proof but no saved App Secret matches the token\'s app — save the correct App Secret on the connection', [
                    'path'  => $request->getUri()->getPath(),
                    'tried' => array_keys(self::candidates($token)),
                ]);
                return \GuzzleHttp\Promise\Create::promiseFor($last);
            }
            $source = array_key_first($left);
            $secret = $left[$source];
            unset($left[$source]);

            return $handler(self::withProof($request, hash_hmac('sha256', $token, $secret)), $options)->then(
                function ($response) use ($try, $left, $token, $source) {
                    try {
                        if ($response->getStatusCode() >= 400 && self::isInvalidProof(self::bodyText($response))) {
                            return $try($left, $response);
                        }
                    } catch (\Throwable $e) {}
                    self::markKnown($token, $source);
                    return $response;
                }
            );
        };

        return $try(self::candidates($token), $lastResponse);
    }

    private static function knownSource(string $token): string
    {
        $key = sha1(trim($token));
        if (! array_key_exists($key, self::$known)) {
            try { self::$known[$key] = (string) Cache::get('waba-proof-src:' . $key, ''); } catch (\Throwable $e) { self::$known[$key] = ''; }
        }
        return self::$known[$key];
    }

    private static function markKnown(string $token, string $source): void
    {
        $key = sha1(trim($token));
        self::$known[$key] = $source;
        try { Cache::put('waba-proof-src:' . $key, $source, now()->addDays(30)); } catch (\Throwable $e) {}
        Log::info('[META] app requires appsecret_proof — using the ' . $source . ' App Secret for this number from now on');
    }

    private static function forgetKnown(string $token): void
    {
        $key = sha1(trim($token));
        self::$known[$key] = '';
        try { Cache::forget('waba-proof-src:' . $key); } catch (\Throwable $e) {}
    }

    /** Keyed to the CURRENT set of secrets — saving a new/correct secret changes the key, so it is tried at once. */
    private static function noMatchKey(string $token): string
    {
        $fp = implode('|', array_map('sha1', array_values(self::candidates($token))));
        return 'waba-proof-nomatch:' . sha1(trim($token) . '|' . $fp);
    }

    private static function markNoMatch(string $token): void
    {
        try { Cache::put(self::noMatchKey($token), 1, now()->addMinutes(10)); } catch (\Throwable $e) {}
    }

    private static function noMatchRecently(string $token): bool
    {
        try { return (bool) Cache::has(self::noMatchKey($token)); } catch (\Throwable $e) { return false; }
    }

    private static function isRequiresProof(string $text): bool
    {
        return (bool) preg_match('/requires?\s+(an?\s+)?appsecret_proof/i', $text);
    }

    private static function isInvalidProof(string $text): bool
    {
        return (bool) preg_match('/invalid\s+appsecret_proof|appsecret_proof[^"]{0,60}invalid/i', $text);
    }

    private static function tokenOf(RequestInterface $request): string
    {
        $auth = $request->getHeaderLine('Authorization');
        if (stripos($auth, 'Bearer ') === 0) return trim(substr($auth, 7));
        if (preg_match('/(?:^|&)access_token=([^&]+)/', $request->getUri()->getQuery(), $m)) return urldecode($m[1]);
        return '';
    }

    private static function withProof(RequestInterface $request, string $proof): RequestInterface
    {
        $uri   = $request->getUri();
        $query = $uri->getQuery();
        return $request->withUri($uri->withQuery($query . ($query === '' ? '' : '&') . 'appsecret_proof=' . $proof));
    }

    private static function bodyText($response): string
    {
        $body = $response->getBody();
        $text = (string) $body;
        if ($body->isSeekable()) $body->rewind();
        return $text;
    }
}
