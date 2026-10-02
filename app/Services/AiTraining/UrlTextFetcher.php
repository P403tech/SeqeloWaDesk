<?php

namespace App\Services\AiTraining;

use Illuminate\Support\Facades\Http;

/**
 * SSRF-safe fetch of a public web page → readable plain text.
 *
 * Extracted from AiTrainingController so BOTH the AI-Training URL source AND
 * the AI voice agent's "knowledge base URL" crawl through the SAME guarded
 * fetcher (one place to harden). Refuses non-http(s) schemes and any host that
 * resolves to a private/loopback/link-local/reserved IP (RFC1918, 127/8,
 * 169.254/16 incl. cloud metadata, ::1, fc00::/7…), re-validating every
 * redirect hop. 20s timeout, 80KB cap.
 */
class UrlTextFetcher
{
    /** @return array{0:bool,1:?string,2:?string} [ok, text, error] */
    public function fetch(string $url): array
    {
        try {
            // Follow redirects MANUALLY so the SSRF guard runs on EVERY hop.
            $current      = $url;
            $maxRedirects = 5;
            $res          = null;
            for ($hop = 0; $hop <= $maxRedirects; $hop++) {
                $ssrfErr = $this->guardSsrf($current);
                if ($ssrfErr) return [false, null, $ssrfErr];

                $res = Http::timeout(20)
                    ->withOptions(['allow_redirects' => false])
                    ->withHeaders(['User-Agent' => 'WaDeskAITrainingBot/1.0'])
                    ->get($current);

                if ($res->redirect()) {
                    $loc = (string) $res->header('Location');
                    if ($loc === '') break; // 3xx without Location — treat as final
                    if ($hop === $maxRedirects) {
                        return [false, null, 'too many redirects'];
                    }
                    $current = $this->resolveRedirectUrl($current, $loc);
                    continue;
                }
                break;
            }
            if (!$res || !$res->ok()) {
                return [false, null, 'fetch failed: HTTP ' . ($res ? $res->status() : 0)];
            }
            $html = (string) $res->body();
            $html = preg_replace('#<script\b[^>]*>(.*?)</script>#is', ' ', $html) ?? $html;
            $html = preg_replace('#<style\b[^>]*>(.*?)</style>#is',  ' ', $html) ?? $html;
            $text = html_entity_decode(strip_tags($html));
            $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
            $text = trim($text);
            if ($text === '') return [false, null, 'fetched page contained no text'];
            if (mb_strlen($text) > 80000) $text = mb_substr($text, 0, 80000);
            return [true, $text, null];
        } catch (\Throwable $e) {
            return [false, null, 'fetch exception: ' . $e->getMessage()];
        }
    }

    private function resolveRedirectUrl(string $base, string $location): string
    {
        $location = trim($location);
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $location)) {
            return $location;
        }
        $b = parse_url($base);
        if (!$b || empty($b['scheme']) || empty($b['host'])) {
            return $location;
        }
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if ($location === '') return $origin;
        if ($location[0] === '/') {
            if (isset($location[1]) && $location[1] === '/') {
                return $b['scheme'] . ':' . $location;
            }
            return $origin . $location;
        }
        $path = $b['path'] ?? '/';
        $dir  = substr($path, 0, strrpos($path, '/') + 1) ?: '/';
        return $origin . $dir . $location;
    }

    /** NULL when safe to fetch, else a human-readable refusal reason. */
    public function guardSsrf(string $url): ?string
    {
        $p = parse_url($url);
        if (!$p || empty($p['scheme']) || empty($p['host'])) {
            return 'invalid URL';
        }
        $scheme = strtolower($p['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return "scheme {$scheme} not allowed (use http or https)";
        }
        $host = strtolower($p['host']);
        if (str_contains($host, 'metadata.') || str_ends_with($host, '.internal')) {
            return 'metadata host not allowed';
        }
        $ips = @gethostbynamel($host) ?: [];
        if (filter_var($host, FILTER_VALIDATE_IP)) $ips = [$host];
        if (empty($ips)) {
            $aaaa = @dns_get_record($host, DNS_AAAA);
            foreach ((array) $aaaa as $rec) {
                if (!empty($rec['ipv6'])) $ips[] = $rec['ipv6'];
            }
        }
        if (empty($ips)) {
            return 'hostname did not resolve to a public IP';
        }
        foreach ($ips as $ip) {
            $public = filter_var($ip, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if ($public === false) {
                return "host resolves to private/reserved IP ({$ip}) — refusing to fetch";
            }
        }
        return null;
    }
}
