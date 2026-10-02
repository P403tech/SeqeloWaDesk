<?php

namespace App\Support;

use App\Models\SystemSetting;
use App\Models\WaProviderConfig;
use Illuminate\Support\Facades\Http;

/**
 * One-off support diagnostic for "templates don't work on this WABA".
 *
 * Served as a ROUTE rather than a standalone file in public/ — most hardened
 * nginx configs only pass index.php to PHP-FPM, so a dropped-in .php script
 * 404s or downloads as text. Going through the router sidesteps that entirely
 * and gets the app's decrypted credentials for free.
 *
 * Read-only: every Graph call is a GET and nothing is written.
 *
 * It answers the three questions that separate the real causes:
 *   1. Does the token carry whatsapp_business_management? Without it NO
 *      template call can work, whatever the ids say.
 *   2. Is the stored waba_id actually a WABA, or the phone_number_id? A phone
 *      number object has no message_templates edge — the source of both
 *      "(#100) Tried accessing nonexisting field (message_templates)" and
 *      "(#100/33) … does not support this operation".
 *   3. Does the failing call succeed right now? If yes, the connection is fine
 *      and the deployed code is stale.
 */
class WabaDiagnostic
{
    private string $base;
    private array  $out = [];

    /** Filled by checkScopes() and read by the verdict. */
    private string $tokenAppId   = '';
    private array  $tokenWabaIds = [];

    public function __construct()
    {
        $version    = (string) SystemSetting::get('waba_graph_api_version', 'v23.0');
        $this->base = 'https://graph.facebook.com/' . ltrim($version, '/');
    }

    public function run(): string
    {
        $this->line('WABA TEMPLATE DIAGNOSTIC');
        $this->line('Graph API base: ' . $this->base);
        $this->line(str_repeat('=', 78));
        $this->line('');

        $configs = WaProviderConfig::query()->where('provider', 'waba')->orderBy('id')->get();

        if ($configs->isEmpty()) {
            $this->line('No WABA connections found on this install.');

            return implode("\n", $this->out);
        }

        foreach ($configs as $cfg) {
            $this->inspect($cfg);
        }

        $this->line('Done.');

        return implode("\n", $this->out);
    }

    private function inspect(WaProviderConfig $cfg): void
    {
        $creds    = $cfg->creds();
        $meta     = is_array($cfg->meta_json) ? $cfg->meta_json : [];
        $token    = (string) ($creds['access_token'] ?? '');
        $storedId = (string) ($meta['waba_id'] ?? $creds['waba_id'] ?? '');
        $phoneId  = (string) ($meta['phone_number_id'] ?? $creds['phone_number_id'] ?? '');

        $this->line("CONFIG #{$cfg->id}  —  " . ($cfg->phone_number ?: $cfg->display_label ?: 'unlabelled'));
        $this->line('  workspace_id    : ' . $cfg->workspace_id);
        $this->line('  status          : ' . $cfg->status);
        $this->line('  access_token    : ' . $this->mask($token));
        $this->line('  stored waba_id  : ' . ($storedId ?: '(empty)'));
        $this->line('  phone_number_id : ' . ($phoneId ?: '(empty)'));
        $this->line('');

        if ($token === '') {
            $this->line('  VERDICT: no access token stored — reconnect this number.');
            $this->line(str_repeat('-', 78));
            $this->line('');

            return;
        }

        $hasMgmt   = $this->checkScopes($token);
        $realWaba  = $this->checkStoredId($token, $storedId, $phoneId);
        $tplWorks  = $this->checkTemplatesEdge($token, $storedId);

        $this->line('  VERDICT:');
        if ($storedId !== '' && $storedId === $this->tokenAppId) {
            // An App object answers id+name happily, so a plain existence check
            // calls it "a valid WABA". Only comparing it against the token's
            // own app_id exposes it.
            $this->line('    THE APP ID IS STORED IN THE waba_id SLOT.');
            $this->line("    {$storedId} is this token's Meta App ID, not a WhatsApp Business Account.");
            $this->line('    An App has no message_templates edge, which is exactly the error seen.');
            if ($this->tokenWabaIds) {
                $this->line('    CORRECT waba_id: ' . implode(' or ', $this->tokenWabaIds));
            } else {
                $this->line('    The token is not scoped to any WABA — reconnect to capture one.');
            }
        } elseif ($hasMgmt === false) {
            $this->line('    TOKEN IS MISSING whatsapp_business_management.');
            $this->line('    No template call can succeed until the token is regenerated with');
            $this->line('    business_management + whatsapp_business_management +');
            $this->line('    whatsapp_business_messaging, with Full Control over the WABA.');
            $this->line('    Sending keeps working without it — that is why it went unnoticed.');
        } elseif ($realWaba !== '' && $storedId !== '' && $realWaba !== $storedId) {
            $this->line('    WRONG waba_id STORED.');
            $this->line("    Stored {$storedId}, but the number belongs to {$realWaba}.");
            $this->line('    Fix: click "Sync from Meta" once on a build with the self-heal,');
            $this->line('    or set meta_json.waba_id to the correct value.');
        } elseif ($tplWorks) {
            $this->line('    Connection is HEALTHY — the templates endpoint works right now.');
            $this->line('    If the UI still shows the error, the deployed code is stale:');
            $this->line('    reload PHP-FPM, not just php artisan optimize:clear.');
        } else {
            $this->line('    Inconclusive — send this output for review.');
        }

        $this->line('');
        $this->line(str_repeat('-', 78));
        $this->line('');
    }

    /** @return bool|null true/false, or null when the token could not be inspected. */
    private function checkScopes(string $token): ?bool
    {
        $this->line('  [1] Token permissions');
        $r = $this->get("{$this->base}/debug_token", ['input_token' => $token], $token);

        if (! $r['ok']) {
            $this->line('      could not inspect token (HTTP ' . $r['status'] . '): '
                . (string) data_get($r['json'], 'error.message', '?'));
            $this->line('');

            return null;
        }

        $scopes   = (array) data_get($r['json'], 'data.scopes', []);
        $granular = array_map(
            static fn ($g) => (string) ($g['scope'] ?? ''),
            (array) data_get($r['json'], 'data.granular_scopes', [])
        );
        $all = array_values(array_filter(array_unique(array_merge($scopes, $granular))));

        $this->line('      scopes : ' . ($all ? implode(', ', $all) : '(none reported)'));
        $has = in_array('whatsapp_business_management', $all, true);
        $this->line('      whatsapp_business_management : ' . ($has ? 'YES' : 'NO  <-- required for templates'));

        $appId = (string) data_get($r['json'], 'data.app_id', '?');
        $this->line('      app_id : ' . $appId);

        // The WABA ids this token was actually granted. This is the reliable
        // answer when business_id is missing — and comparing app_id with the
        // stored waba_id is what reveals the App ID having been saved in the
        // WABA slot, which looks valid to a naive GET (an App has id + name).
        $targets = [];
        foreach ((array) data_get($r['json'], 'data.granular_scopes', []) as $g) {
            if ((string) ($g['scope'] ?? '') === 'whatsapp_business_management') {
                foreach ((array) ($g['target_ids'] ?? []) as $t) {
                    $targets[] = (string) $t;
                }
            }
        }
        $targets = array_values(array_unique(array_filter($targets)));
        $this->line('      WABA ids granted to this token : '
            . ($targets ? implode(', ', $targets) : '(none — token is not scoped to any WABA)'));

        $this->tokenAppId    = $appId;
        $this->tokenWabaIds  = $targets;

        $this->line('');

        return $has;
    }

    /** @return string the WABA id that really owns the number, or ''. */
    private function checkStoredId(string $token, string $storedId, string $phoneId): string
    {
        $this->line('  [2] What the stored id actually is');

        if ($storedId === '') {
            $this->line('      (empty)');
        } else {
            $asPhone = $this->get("{$this->base}/{$storedId}",
                ['fields' => 'whatsapp_business_account{id,name},display_phone_number'], $token);
            $owner = (string) data_get($asPhone['json'], 'whatsapp_business_account.id', '');

            if ($owner !== '') {
                $this->line('      *** It is a PHONE NUMBER, not a WABA. ***');
                $this->line('      display_phone_number : ' . (string) data_get($asPhone['json'], 'display_phone_number', '?'));
                $this->line("      CORRECT waba_id      : {$owner}");
            } else {
                $asWaba = $this->get("{$this->base}/{$storedId}", ['fields' => 'id,name'], $token);
                if ($asWaba['ok']) {
                    $this->line('      Valid WABA: ' . (string) data_get($asWaba['json'], 'name', '?'));
                } else {
                    $this->line('      Meta will not load it (HTTP ' . $asWaba['status'] . '): '
                        . (string) data_get($asWaba['json'], 'error.message', '?'));
                    $this->line('      code/subcode: ' . (string) data_get($asWaba['json'], 'error.code', '?')
                        . '/' . (string) data_get($asWaba['json'], 'error.error_subcode', '-'));
                }
            }
        }
        $this->line('');

        $this->line('  [3] WABA that owns phone_number_id');
        $real = '';
        if ($phoneId === '') {
            $this->line('      (no phone_number_id stored)');
        } else {
            $pn   = $this->get("{$this->base}/{$phoneId}",
                ['fields' => 'whatsapp_business_account{id,name},display_phone_number'], $token);
            $real = (string) data_get($pn['json'], 'whatsapp_business_account.id', '');

            if ($real !== '') {
                $this->line("      resolved waba_id : {$real}  ("
                    . (string) data_get($pn['json'], 'whatsapp_business_account.name', '?') . ')');
                $this->line('      matches stored   : ' . ($real === $storedId ? 'YES' : 'NO  <-- stored value is wrong'));
            } else {
                $this->line('      could not resolve (HTTP ' . $pn['status'] . '): '
                    . (string) data_get($pn['json'], 'error.message', '?'));
                $this->line('      This is the call the self-heal makes — it needs whatsapp_business_management.');
            }
        }
        $this->line('');

        return $real;
    }

    private function checkTemplatesEdge(string $token, string $storedId): bool
    {
        $this->line('  [4] Live test: GET /{stored_id}/message_templates');

        if ($storedId === '') {
            $this->line('      (skipped — no stored id)');
            $this->line('');

            return false;
        }

        $r = $this->get("{$this->base}/{$storedId}/message_templates", ['limit' => 1], $token);

        if ($r['ok']) {
            $this->line('      SUCCESS — endpoint reachable ('
                . count((array) data_get($r['json'], 'data', [])) . ' row(s))');
        } else {
            $this->line('      FAILED (HTTP ' . $r['status'] . ') code '
                . (string) data_get($r['json'], 'error.code', '?') . '/'
                . (string) data_get($r['json'], 'error.error_subcode', '-'));
            $this->line('      ' . (string) data_get($r['json'], 'error.message', '?'));
        }
        $this->line('');

        return (bool) $r['ok'];
    }

    private function get(string $url, array $query, string $token): array
    {
        try {
            $r = Http::withToken($token)->timeout(15)->get($url, $query);

            return ['ok' => $r->successful(), 'status' => $r->status(), 'json' => $r->json()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'json' => ['error' => ['message' => $e->getMessage()]]];
        }
    }

    private function mask(string $s): string
    {
        $len = strlen($s);
        if ($len === 0)  return '(empty)';
        if ($len <= 12)  return str_repeat('*', $len) . " (len {$len})";

        return substr($s, 0, 6) . str_repeat('*', 6) . substr($s, -4) . " (len {$len})";
    }

    private function line(string $s): void
    {
        $this->out[] = $s;
    }
}
