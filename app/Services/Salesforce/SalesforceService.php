<?php

namespace App\Services\Salesforce;

use App\Models\SalesforceIntegration;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Salesforce Connected App OAuth + REST helpers. Mirrors HubspotService:
 * platform credentials in system_settings, per-workspace tokens on the row.
 * Contact/Opportunity sync is added later — this class is the connect path.
 */
class SalesforceService
{
    private const HTTP_TIMEOUT_SECONDS = 20;

    public const DEFAULT_SCOPES = 'api refresh_token openid';

    public function clientId(): string
    {
        return (string) SystemSetting::get('salesforce_client_id', '');
    }

    public function clientSecret(): string
    {
        return (string) SystemSetting::get('salesforce_client_secret', '');
    }

    public function scopes(): string
    {
        return (string) SystemSetting::get('salesforce_scopes', self::DEFAULT_SCOPES);
    }

    public function redirectUri(): string
    {
        return (string) (SystemSetting::get('salesforce_redirect_uri') ?: url('/salesforce/oauth/callback'));
    }

    public function isEnabled(): bool
    {
        return (bool) SystemSetting::get('salesforce_enabled', false);
    }

    public function loginHost(): string
    {
        $host = strtolower(trim((string) SystemSetting::get('salesforce_login_host', 'login.salesforce.com')));
        if (! in_array($host, ['login.salesforce.com', 'test.salesforce.com'], true)) {
            return 'login.salesforce.com';
        }

        return $host;
    }

    public static function generatePkce(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return ['verifier' => $verifier, 'challenge' => $challenge];
    }

    public function authorizeUrl(string $state, ?string $codeChallenge = null): string
    {
        $params = [
            'response_type' => 'code',
            'client_id'     => $this->clientId(),
            'redirect_uri'  => $this->redirectUri(),
            'scope'         => $this->scopes(),
            'state'         => $state,
        ];
        if ($codeChallenge) {
            $params['code_challenge'] = $codeChallenge;
            $params['code_challenge_method'] = 'S256';
        }

        return 'https://' . $this->loginHost() . '/services/oauth2/authorize?' . http_build_query($params);
    }

    public function exchangeCode(string $code, ?string $codeVerifier = null): array
    {
        try {
            $body = [
                'grant_type'    => 'authorization_code',
                'client_id'     => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'redirect_uri'  => $this->redirectUri(),
                'code'          => $code,
            ];
            if ($codeVerifier) {
                $body['code_verifier'] = $codeVerifier;
            }
            $r = Http::asForm()->timeout(self::HTTP_TIMEOUT_SECONDS)
                ->post('https://' . $this->loginHost() . '/services/oauth2/token', $body);
            if ($r->successful()) {
                return [
                    'success'       => true,
                    'access_token'  => $r->json('access_token'),
                    'refresh_token' => $r->json('refresh_token'),
                    'instance_url'  => $r->json('instance_url'),
                    'expires_in'    => (int) $r->json('expires_in', 7200),
                    'id'            => $r->json('id'),
                ];
            }

            return ['success' => false, 'error' => $r->json('error_description') ?: $r->json('error') ?: ('HTTP ' . $r->status())];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function refreshAccessToken(SalesforceIntegration $integration): bool
    {
        try {
            $host = $integration->login_host ?: $this->loginHost();
            $r = Http::asForm()->timeout(self::HTTP_TIMEOUT_SECONDS)
                ->post('https://' . $host . '/services/oauth2/token', [
                    'grant_type'    => 'refresh_token',
                    'client_id'     => $this->clientId(),
                    'client_secret' => $this->clientSecret(),
                    'refresh_token' => $integration->refresh_token,
                ]);
            if (! $r->successful()) {
                $integration->update(['status' => 'error']);

                return false;
            }
            $integration->update([
                'access_token'            => $r->json('access_token'),
                'instance_url'            => $r->json('instance_url') ?: $integration->instance_url,
                'access_token_expires_at' => now()->addSeconds((int) $r->json('expires_in', 7200)),
                'status'                  => 'active',
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('[SALESFORCE] refresh failed: ' . $e->getMessage());

            return false;
        }
    }

    /** Org identity after OAuth — used to label the integration row. */
    public function getOrgInfo(string $accessToken, string $instanceUrl): array
    {
        try {
            $base = rtrim($instanceUrl, '/');
            $r = Http::withToken($accessToken)->timeout(self::HTTP_TIMEOUT_SECONDS)
                ->get($base . '/services/oauth2/userinfo');
            if ($r->successful()) {
                return [
                    'org_id'    => (string) ($r->json('organization_id') ?: ''),
                    'org_name'  => (string) ($r->json('organization_name') ?: $r->json('name') ?: ''),
                    'org_email' => (string) ($r->json('email') ?: $r->json('preferred_username') ?: ''),
                ];
            }
        } catch (\Throwable $e) {
        }

        return [];
    }
}
