<?php

namespace App\Services\Waba;

use App\Models\SystemSetting;
use App\Models\WaProviderConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Official WhatsApp Cloud API "Block API" — blocks / unblocks a customer at the
 * Meta platform level so Meta stops delivering their messages to our webhook
 * (and the business can't message them either).
 *
 *   Block   : POST   /{phone-number-id}/block_users
 *   Unblock : DELETE /{phone-number-id}/block_users
 *   List    : GET    /{phone-number-id}/block_users
 *   Body    : { "messaging_product": "whatsapp", "block_users": [ { "user": "<phone>" } ] }
 *
 * Meta limits (docs): you can only block a user who messaged you in the last
 * 24h; max 1000 users / request; 64k blocklist. Those failures come back in the
 * response and are surfaced, never thrown — the LOCAL block always still applies.
 *
 * This is WABA-only. Instagram / Messenger have no messaging block API, and the
 * unofficial WhatsApp engine has none either — those rely on the local block.
 */
class WabaBlockClient
{
    private string $base;
    private string $phoneId;
    private string $token;

    public function __construct(WaProviderConfig $cfg)
    {
        $creds         = $cfg->creds();
        $version       = (string) SystemSetting::get('waba_graph_api_version', 'v23.0');
        $this->base    = 'https://graph.facebook.com/' . ltrim($version, '/');
        $this->token   = (string) ($creds['access_token'] ?? '');
        $this->phoneId = (string) (($cfg->meta_json['phone_number_id'] ?? null) ?: ($creds['phone_number_id'] ?? ''));
    }

    /** A connected WABA/Twilio-less Cloud API provider for the workspace, or null. */
    public static function forWorkspace(int $workspaceId): ?self
    {
        $cfg = WaProviderConfig::where('workspace_id', $workspaceId)
            ->where('provider', \App\Services\WorkspaceEngine::ENGINE_WABA)
            ->where('status', WaProviderConfig::STATUS_CONNECTED)
            ->orderByDesc('connected_at')
            ->first();

        return $cfg ? new self($cfg) : null;
    }

    public function isUsable(): bool
    {
        return $this->token !== '' && $this->phoneId !== '';
    }

    /** Block one or more customer phone numbers. Returns ['ok'=>bool, ...]. */
    public function block(string|array $phones): array
    {
        return $this->call('post', $phones);
    }

    /** Unblock one or more customer phone numbers. */
    public function unblock(string|array $phones): array
    {
        return $this->call('delete', $phones);
    }

    private function call(string $method, string|array $phones): array
    {
        if (! $this->isUsable()) {
            return ['ok' => false, 'error' => 'WABA provider missing access_token or phone_number_id'];
        }
        $users = collect((array) $phones)
            ->map(fn ($p) => preg_replace('/\D+/', '', (string) $p))
            ->filter()
            ->unique()
            ->map(fn ($p) => ['user' => $p])
            ->values()
            ->all();
        if (empty($users)) {
            return ['ok' => false, 'error' => 'no valid phone numbers'];
        }

        $url  = "{$this->base}/{$this->phoneId}/block_users";
        $body = ['messaging_product' => 'whatsapp', 'block_users' => $users];

        try {
            $req = Http::withToken($this->token)->acceptJson()->timeout(20);
            // Meta reads the body from JSON on both POST (block) and DELETE (unblock).
            $res = $method === 'delete'
                ? $req->send('DELETE', $url, ['json' => $body])
                : $req->post($url, $body);

            $json = $res->json() ?? [];
            if ($res->successful() && ! isset($json['error'])) {
                return ['ok' => true, 'response' => $json];
            }
            $msg = (string) ($json['error']['message'] ?? ('HTTP ' . $res->status()));
            Log::warning('[WABA-BLOCK] ' . $method . ' failed', ['phone_id' => $this->phoneId, 'status' => $res->status(), 'error' => $msg]);
            return ['ok' => false, 'error' => $msg, 'response' => $json];
        } catch (\Throwable $e) {
            Log::warning('[WABA-BLOCK] ' . $method . ' exception: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
