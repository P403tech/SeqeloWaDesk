<?php

namespace App\Services\Zoho;

use App\Models\SystemSetting;
use App\Models\ZohoIntegration;
use App\Models\ZohoIntegrationLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Zoho CRM Connected App OAuth 2.0 + REST v6 API client.
 *
 * Supports global Zoho multi-DC architecture:
 * - US / Global: accounts.zoho.com / www.zohoapis.com
 * - Europe:      accounts.zoho.eu / www.zohoapis.eu
 * - India:       accounts.zoho.in / www.zohoapis.in
 * - Australia:   accounts.zoho.com.au / www.zohoapis.com.au
 * - Japan:       accounts.zoho.jp / www.zohoapis.jp
 * - Canada:      accounts.zoho.zohocloud.ca / www.zohoapis.ca
 */
class ZohoService
{
    private const HTTP_TIMEOUT_SECONDS = 25;

    public const DEFAULT_SCOPES = 'ZohoCRM.modules.ALL,ZohoCRM.users.READ,ZohoCRM.org.READ';

    public const DATA_CENTERS = [
        'com' => [
            'label'           => 'United States / Global (.com)',
            'accounts_server' => 'accounts.zoho.com',
            'api_domain'      => 'https://www.zohoapis.com',
        ],
        'eu' => [
            'label'           => 'Europe (.eu)',
            'accounts_server' => 'accounts.zoho.eu',
            'api_domain'      => 'https://www.zohoapis.eu',
        ],
        'in' => [
            'label'           => 'India (.in)',
            'accounts_server' => 'accounts.zoho.in',
            'api_domain'      => 'https://www.zohoapis.in',
        ],
        'com.au' => [
            'label'           => 'Australia (.com.au)',
            'accounts_server' => 'accounts.zoho.com.au',
            'api_domain'      => 'https://www.zohoapis.com.au',
        ],
        'jp' => [
            'label'           => 'Japan (.jp)',
            'accounts_server' => 'accounts.zoho.jp',
            'api_domain'      => 'https://www.zohoapis.jp',
        ],
        'ca' => [
            'label'           => 'Canada (.ca)',
            'accounts_server' => 'accounts.zoho.zohocloud.ca',
            'api_domain'      => 'https://www.zohoapis.ca',
        ],
    ];

    public function clientId(): string
    {
        return trim((string) SystemSetting::get('zoho_client_id', ''));
    }

    public function clientSecret(): string
    {
        return trim((string) SystemSetting::get('zoho_client_secret', ''));
    }

    public function scopes(): string
    {
        return (string) SystemSetting::get('zoho_scopes', self::DEFAULT_SCOPES);
    }

    public function dataCenter(): string
    {
        $dc = (string) SystemSetting::get('zoho_data_center', 'com');
        return array_key_exists($dc, self::DATA_CENTERS) ? $dc : 'com';
    }

    public function accountsServer(?string $dc = null): string
    {
        $dcKey = $dc && array_key_exists($dc, self::DATA_CENTERS) ? $dc : $this->dataCenter();
        return self::DATA_CENTERS[$dcKey]['accounts_server'] ?? 'accounts.zoho.com';
    }

    public function redirectUri(): string
    {
        $custom = SystemSetting::get('zoho_redirect_uri');
        return $custom ? (string) $custom : url('/zoho/oauth/callback');
    }

    public function isEnabled(): bool
    {
        return (bool) SystemSetting::get('zoho_enabled', false);
    }

    /**
     * Build the OAuth authorization URL.
     */
    public function authorizeUrl(string $state, ?string $dc = null): string
    {
        $accounts = $this->accountsServer($dc);
        $params = [
            'scope'         => $this->scopes(),
            'client_id'     => $this->clientId(),
            'response_type' => 'code',
            'access_type'   => 'offline',
            'redirect_uri'  => $this->redirectUri(),
            'prompt'        => 'consent',
            'state'         => $state,
        ];

        return 'https://' . $accounts . '/oauth/v2/auth?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for access and refresh tokens.
     */
    public function exchangeCode(string $code, ?string $dc = null): array
    {
        try {
            $accounts = $this->accountsServer($dc);
            $response = Http::asForm()->timeout(self::HTTP_TIMEOUT_SECONDS)
                ->post('https://' . $accounts . '/oauth/v2/token', [
                    'grant_type'    => 'authorization_code',
                    'client_id'     => $this->clientId(),
                    'client_secret' => $this->clientSecret(),
                    'redirect_uri'  => $this->redirectUri(),
                    'code'          => $code,
                ]);

            if ($response->successful() && $response->json('access_token')) {
                return [
                    'success'         => true,
                    'access_token'    => (string) $response->json('access_token'),
                    'refresh_token'   => (string) ($response->json('refresh_token') ?? ''),
                    'api_domain'      => (string) ($response->json('api_domain') ?? ('https://' . $accounts)),
                    'expires_in'      => (int) ($response->json('expires_in') ?? 3600),
                    'accounts_server' => $accounts,
                ];
            }

            $err = $response->json('error') ?: ('HTTP ' . $response->status());
            Log::warning('Zoho OAuth code exchange failed', ['response' => $response->body()]);

            return ['success' => false, 'error' => (string) $err];
        } catch (\Throwable $e) {
            Log::error('Zoho OAuth exchangeCode exception: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Refresh the integration's access token using its refresh token.
     */
    public function refreshAccessToken(ZohoIntegration $integration): bool
    {
        try {
            if (empty($integration->refresh_token)) {
                return false;
            }

            $accounts = $integration->accounts_server ?: $this->accountsServer();
            $response = Http::asForm()->timeout(self::HTTP_TIMEOUT_SECONDS)
                ->post('https://' . $accounts . '/oauth/v2/token', [
                    'grant_type'    => 'refresh_token',
                    'client_id'     => $this->clientId(),
                    'client_secret' => $this->clientSecret(),
                    'refresh_token' => $integration->refresh_token,
                ]);

            if ($response->successful() && $response->json('access_token')) {
                $expiresIn = (int) ($response->json('expires_in') ?? 3600);
                $integration->update([
                    'access_token'            => (string) $response->json('access_token'),
                    'access_token_expires_at' => now()->addSeconds($expiresIn - 60),
                    'last_verified_at'        => now(),
                ]);

                if ($response->json('api_domain') && empty($integration->api_domain)) {
                    $integration->update(['api_domain' => $response->json('api_domain')]);
                }

                return true;
            }

            Log::warning('Zoho OAuth token refresh failed', [
                'integration_id' => $integration->id,
                'response'       => $response->body(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('Zoho refreshAccessToken error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get valid access token, auto-refreshing if expired or expiring within 3 minutes.
     */
    public function getValidToken(ZohoIntegration $integration): ?string
    {
        if (empty($integration->access_token)) {
            return null;
        }

        $expiresAt = $integration->access_token_expires_at;
        if (! $expiresAt || $expiresAt->isPast() || $expiresAt->diffInSeconds(now()) < 180) {
            $refreshed = $this->refreshAccessToken($integration);
            if (! $refreshed) {
                return null;
            }
            $integration->refresh();
        }

        return $integration->access_token;
    }

    /**
     * Fetch Organization profile from Zoho CRM.
     */
    public function getOrgInfo(string $accessToken, string $apiDomain): array
    {
        try {
            $url = rtrim($apiDomain, '/') . '/crm/v6/org';
            $r = Http::withToken($accessToken, 'Zoho-oauthtoken')
                ->timeout(self::HTTP_TIMEOUT_SECONDS)
                ->get($url);

            if ($r->successful()) {
                $org = $r->json('org.0') ?? $r->json('org') ?? [];
                return [
                    'org_id'    => (string) ($org['id'] ?? $org['zgid'] ?? ''),
                    'org_name'  => (string) ($org['company_name'] ?? $org['name'] ?? ''),
                    'org_email' => (string) ($org['primary_email'] ?? $org['email'] ?? ''),
                ];
            }

            return [];
        } catch (\Throwable $e) {
            Log::warning('Zoho getOrgInfo error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Upsert a Contact in Zoho CRM v6.
     * Checks duplicates on Email or Phone.
     */
    public function upsertContact(ZohoIntegration $integration, array $contactData): array
    {
        $token = $this->getValidToken($integration);
        if (! $token) {
            return ['success' => false, 'error' => 'Unable to acquire valid Zoho token'];
        }

        $apiDomain = $integration->api_domain ?: 'https://www.zohoapis.com';
        $url = rtrim($apiDomain, '/') . '/crm/v6/Contacts/upsert';

        try {
            $body = [
                'data'                   => [$contactData],
                'duplicate_check_fields' => ['Email', 'Phone'],
            ];

            $response = Http::withToken($token, 'Zoho-oauthtoken')
                ->timeout(self::HTTP_TIMEOUT_SECONDS)
                ->post($url, $body);

            $data = $response->json();
            $first = $data['data'][0] ?? [];

            $isSuccess = ($first['status'] ?? '') === 'success' || $response->successful();
            $contactId = $first['details']['id'] ?? null;

            ZohoIntegrationLog::create([
                'integration_id' => $integration->id,
                'event_type'     => 'contact.upsert',
                'status'         => $isSuccess ? 'sent' : 'failed',
                'object_id'      => (string) ($contactId ?? ''),
                'payload'        => $contactData,
                'response'       => $data,
                'error'          => $isSuccess ? null : ($first['message'] ?? $response->body()),
                'created_at'     => now(),
            ]);

            return [
                'success'    => $isSuccess,
                'contact_id' => $contactId,
                'action'     => $first['action'] ?? null,
                'error'      => $isSuccess ? null : ($first['message'] ?? 'Upsert failed'),
            ];
        } catch (\Throwable $e) {
            ZohoIntegrationLog::create([
                'integration_id' => $integration->id,
                'event_type'     => 'contact.upsert',
                'status'         => 'failed',
                'payload'        => $contactData,
                'error'          => $e->getMessage(),
                'created_at'     => now(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Upsert a Lead in Zoho CRM v6.
     */
    public function upsertLead(ZohoIntegration $integration, array $leadData): array
    {
        $token = $this->getValidToken($integration);
        if (! $token) {
            return ['success' => false, 'error' => 'Unable to acquire valid Zoho token'];
        }

        $apiDomain = $integration->api_domain ?: 'https://www.zohoapis.com';
        $url = rtrim($apiDomain, '/') . '/crm/v6/Leads/upsert';

        try {
            $body = [
                'data'                   => [$leadData],
                'duplicate_check_fields' => ['Email', 'Phone'],
            ];

            $response = Http::withToken($token, 'Zoho-oauthtoken')
                ->timeout(self::HTTP_TIMEOUT_SECONDS)
                ->post($url, $body);

            $data = $response->json();
            $first = $data['data'][0] ?? [];

            $isSuccess = ($first['status'] ?? '') === 'success' || $response->successful();
            $leadId = $first['details']['id'] ?? null;

            ZohoIntegrationLog::create([
                'integration_id' => $integration->id,
                'event_type'     => 'lead.upsert',
                'status'         => $isSuccess ? 'sent' : 'failed',
                'object_id'      => (string) ($leadId ?? ''),
                'payload'        => $leadData,
                'response'       => $data,
                'error'          => $isSuccess ? null : ($first['message'] ?? $response->body()),
                'created_at'     => now(),
            ]);

            return [
                'success' => $isSuccess,
                'lead_id' => $leadId,
                'action'  => $first['action'] ?? null,
                'error'   => $isSuccess ? null : ($first['message'] ?? 'Upsert lead failed'),
            ];
        } catch (\Throwable $e) {
            ZohoIntegrationLog::create([
                'integration_id' => $integration->id,
                'event_type'     => 'lead.upsert',
                'status'         => 'failed',
                'payload'        => $leadData,
                'error'          => $e->getMessage(),
                'created_at'     => now(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Create a Deal / Potential in Zoho CRM v6.
     */
    public function createDeal(ZohoIntegration $integration, array $dealData): array
    {
        $token = $this->getValidToken($integration);
        if (! $token) {
            return ['success' => false, 'error' => 'Unable to acquire valid Zoho token'];
        }

        $apiDomain = $integration->api_domain ?: 'https://www.zohoapis.com';
        $url = rtrim($apiDomain, '/') . '/crm/v6/Deals';

        try {
            $body = ['data' => [$dealData]];
            $response = Http::withToken($token, 'Zoho-oauthtoken')
                ->timeout(self::HTTP_TIMEOUT_SECONDS)
                ->post($url, $body);

            $data = $response->json();
            $first = $data['data'][0] ?? [];

            $isSuccess = ($first['status'] ?? '') === 'success' || $response->successful();
            $dealId = $first['details']['id'] ?? null;

            ZohoIntegrationLog::create([
                'integration_id' => $integration->id,
                'event_type'     => 'deal.created',
                'status'         => $isSuccess ? 'sent' : 'failed',
                'object_id'      => (string) ($dealId ?? ''),
                'payload'        => $dealData,
                'response'       => $data,
                'error'          => $isSuccess ? null : ($first['message'] ?? $response->body()),
                'created_at'     => now(),
            ]);

            return [
                'success' => $isSuccess,
                'deal_id' => $dealId,
                'error'   => $isSuccess ? null : ($first['message'] ?? 'Deal create failed'),
            ];
        } catch (\Throwable $e) {
            ZohoIntegrationLog::create([
                'integration_id' => $integration->id,
                'event_type'     => 'deal.created',
                'status'         => 'failed',
                'payload'        => $dealData,
                'error'          => $e->getMessage(),
                'created_at'     => now(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Test connection to Zoho CRM API.
     */
    public function testConnection(ZohoIntegration $integration): array
    {
        $token = $this->getValidToken($integration);
        if (! $token) {
            return ['success' => false, 'message' => 'Invalid or expired tokens.'];
        }

        $apiDomain = $integration->api_domain ?: 'https://www.zohoapis.com';
        $info = $this->getOrgInfo($token, $apiDomain);

        if (! empty($info['org_id']) || ! empty($info['org_name'])) {
            $integration->update([
                'org_id'           => $info['org_id'] ?: $integration->org_id,
                'org_name'         => $info['org_name'] ?: $integration->org_name,
                'org_email'        => $info['org_email'] ?: $integration->org_email,
                'last_verified_at' => now(),
            ]);

            return [
                'success'  => true,
                'message'  => 'Connected to Zoho CRM: ' . ($info['org_name'] ?: $info['org_id']),
                'org_info' => $info,
            ];
        }

        return ['success' => false, 'message' => 'Unable to read organization info from Zoho CRM API.'];
    }
}
