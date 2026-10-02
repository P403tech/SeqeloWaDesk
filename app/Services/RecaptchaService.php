<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google reCAPTCHA (v2 checkbox or v3 score) verification, admin-configured
 * in system_settings. SDK-free — a single siteverify POST.
 *
 *   recaptcha_enabled   bool
 *   recaptcha_version   'v2' | 'v3'
 *   recaptcha_site_key  string (client)
 *   recaptcha_secret    string (server, encrypted)
 *   recaptcha_v3_threshold  float (default 0.5, v3 only)
 */
class RecaptchaService
{
    /**
     * On a white-label custom domain, captcha resolves from the TENANT
     * workspace's own keys — the platform site key is invalid for that host
     * ("Invalid domain for site key"). A tenant that has NOT configured its own
     * keys simply gets no captcha (enabled() = false) rather than an error. On
     * the platform host this returns null → global admin settings are used.
     */
    private function tenant(): ?\App\Models\Workspace
    {
        try {
            $ws = \App\Http\Middleware\ResolveTenantDomain::tenant();
        } catch (\Throwable $e) {
            return null;
        }
        return $ws && $ws->custom_domain ? $ws : null;
    }

    public function enabled(): bool
    {
        if ($ws = $this->tenant()) {
            // Tenant domain: only when the workspace supplied its own keys.
            return (bool) $ws->captcha_enabled
                && trim((string) $ws->captcha_site_key) !== ''
                && trim((string) $ws->captcha_secret) !== '';
        }

        return (bool) SystemSetting::get('recaptcha_enabled', false)
            && $this->siteKey() !== '' && $this->secret() !== '';
    }

    public function version(): string
    {
        if ($ws = $this->tenant()) {
            return ($ws->captcha_version ?? 'v2') === 'v3' ? 'v3' : 'v2';
        }
        return SystemSetting::get('recaptcha_version', 'v2') === 'v3' ? 'v3' : 'v2';
    }

    public function siteKey(): string
    {
        if ($ws = $this->tenant()) {
            return trim((string) $ws->captcha_site_key);
        }
        return trim((string) SystemSetting::get('recaptcha_site_key', ''));
    }

    public function secret(): string
    {
        if ($ws = $this->tenant()) {
            return trim((string) $ws->captcha_secret);
        }
        return trim((string) SystemSetting::get('recaptcha_secret', ''));
    }

    public function threshold(): float { return (float) (SystemSetting::get('recaptcha_v3_threshold', 0.5) ?: 0.5); }

    /**
     * Verify the client token. Returns true when reCAPTCHA is disabled (so
     * callers can guard with one line) and on a genuine pass. v3 also checks
     * the score against the configured threshold.
     */
    public function verify(?string $token, ?string $ip = null, string $action = 'login'): bool
    {
        if (!$this->enabled()) return true;
        if (!$token) return false;

        try {
            $r = Http::asForm()->timeout(10)->post('https://www.google.com/recaptcha/api/siteverify', array_filter([
                'secret'   => $this->secret(),
                'response' => $token,
                'remoteip' => $ip,
            ]));
            if (!$r->successful() || !$r->json('success')) return false;

            if ($this->version() === 'v3') {
                $score = (float) ($r->json('score') ?? 0);
                return $score >= $this->threshold();
            }
            return true;
        } catch (\Throwable $e) {
            Log::warning('[RECAPTCHA] verify failed: ' . $e->getMessage());
            return false;
        }
    }
}
