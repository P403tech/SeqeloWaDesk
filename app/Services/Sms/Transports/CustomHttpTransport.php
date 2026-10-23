<?php

namespace App\Services\Sms\Transports;

use App\Services\Sms\Contracts\SmsTransport;
use App\Services\Sms\SmsSegments;
use App\Services\Sms\SmsSender;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Custom / bring-your-own SMS gateway.
 *
 * Unlike the hardcoded vendor transports (Twilio, MSG91, …) this one has NO
 * fixed request shape — the workspace describes its provider's HTTP API and we
 * build the call from that description. One slug ('custom'), any gateway.
 *
 * The description lives on the SMS sender's config row:
 *   meta_json.custom_send = {
 *     method:'POST'|'GET', url:'https://…{to}…', content_type:'json'|'form'|'query',
 *     headers:{ 'Authorization':'Bearer {api_key}' }, body:'{"to":"{to}","text":"{text}"}',
 *     success_contains:'', message_id_path:'data.id'
 *   }
 * Secrets ({api_key}/{api_secret}) are injected from the ENCRYPTED creds at send
 * time, so the template stores only the placeholders, never the key.
 *
 * Placeholders: {to} {to_plain} {to_e164} · {text} {text_url} · {from}
 *               {sender_id} {api_key} {api_secret} {segments}
 *
 * Contract rule: NEVER throws — a bad gateway returns a readable error so a
 * campaign is never killed mid-run.
 */
class CustomHttpTransport implements SmsTransport
{
    private const TIMEOUT = 20;

    public function __construct(private SmsSender $sender) {}

    public function provider(): string
    {
        return 'custom';
    }

    public function send(string $to, string $body): array
    {
        $cfg = $this->sendConfig();
        $url = trim((string) ($cfg['url'] ?? ''));
        if ($url === '') {
            return ['ok' => false, 'error' => 'This custom SMS gateway has no send URL configured.'];
        }

        $toDigits = preg_replace('/\D+/', '', $to);
        if ($toDigits === '') {
            return ['ok' => false, 'error' => 'That recipient number could not be read as a phone number.'];
        }
        $body = trim($body);
        if ($body === '') {
            return ['ok' => false, 'error' => 'Nothing to send — the message is empty.'];
        }

        $segments = SmsSegments::measure($body)['segments'];
        $vars = $this->vars($toDigits, $body, $segments);

        try {
            $res = (($cfg['mode'] ?? 'template') === 'params')
                ? $this->sendParams($cfg, $url, $toDigits, $body, $vars)
                : $this->sendTemplate($cfg, $url, $vars);
        } catch (\Throwable $e) {
            Log::warning('[SMS/Custom] send threw: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not reach the SMS gateway: ' . $e->getMessage()];
        }

        return $this->result($res, $cfg, $segments);
    }

    /** TEMPLATE mode — the send URL / body carry {placeholders}. */
    private function sendTemplate(array $cfg, string $url, array $vars)
    {
        $method      = strtoupper((string) ($cfg['method'] ?? 'POST')) === 'GET' ? 'GET' : 'POST';
        $contentType = strtolower((string) ($cfg['content_type'] ?? 'json'));
        $req         = Http::withHeaders($this->buildHeaders((array) ($cfg['headers'] ?? []), $vars))->timeout(self::TIMEOUT);
        if (! empty($cfg['ignore_ssl'])) $req = $req->withoutVerifying();
        $sendUrl = self::subst($url, $vars);

        if ($method === 'GET') return $req->get($sendUrl);
        if ($contentType === 'json') return $req->withBody(self::subst((string) ($cfg['body'] ?? ''), $vars), 'application/json')->post($sendUrl);
        if ($contentType === 'query') return $req->post(self::appendQuery($sendUrl, self::subst((string) ($cfg['body'] ?? ''), $vars)));
        parse_str(self::subst((string) ($cfg['body'] ?? ''), $vars), $form);
        return $req->asForm()->post($sendUrl, $form);
    }

    /**
     * PARAMETER-MAPPING mode — "Create your own server": each logical field maps
     * to the provider's parameter NAME. `dest`/`message` are the recipient/text;
     * other rows carry a static value (which may itself use {placeholders} such as
     * {api_key}). A row's `on_url` (or a GET method) puts it in the query string,
     * otherwise it goes in the POST body.
     */
    private function sendParams(array $cfg, string $url, string $toDigits, string $body, array $vars)
    {
        $method = strtoupper((string) ($cfg['method'] ?? 'GET')) === 'POST' ? 'POST' : 'GET';
        $urlPairs = []; $bodyPairs = [];

        foreach ((array) ($cfg['params'] ?? []) as $key => $p) {
            if (! is_array($p)) continue;
            $name = trim((string) ($p['name'] ?? ''));
            if ($name === '') continue;
            if ($key === 'dest')         $val = $toDigits;
            elseif ($key === 'message')  $val = $body;
            else {
                $val = self::subst((string) ($p['value'] ?? ''), $vars);
                if ($key === 'source' && $val === '') $val = $vars['{sender_id}'];
            }
            if (! empty($p['on_url']) || $method === 'GET') $urlPairs[$name] = $val;
            else $bodyPairs[$name] = $val;
        }

        $req = Http::timeout(self::TIMEOUT);
        if (! empty($cfg['ignore_ssl'])) $req = $req->withoutVerifying();

        $headers = [];
        if (! empty($cfg['accept']))              $headers['Accept'] = (string) $cfg['accept'];
        if (! empty($cfg['content_type_header'])) $headers['Content-Type'] = (string) $cfg['content_type_header'];
        if ($headers) $req = $req->withHeaders($headers);

        $auth = (string) ($cfg['auth'] ?? 'params');
        if ($auth === 'basic')  $req = $req->withBasicAuth($vars['{api_key}'], $vars['{api_secret}']);
        elseif ($auth === 'bearer') $req = $req->withToken($vars['{api_key}']);

        $finalUrl = $urlPairs ? self::appendQuery($url, http_build_query($urlPairs)) : $url;

        if ($method === 'GET') return $req->get($finalUrl);
        return ! empty($cfg['json_post']) ? $req->asJson()->post($finalUrl, $bodyPairs) : $req->asForm()->post($finalUrl, $bodyPairs);
    }

    /** Shared response handling for both modes. */
    private function result($res, array $cfg, int $segments): array
    {
        $respBody = (string) $res->body();
        $needle   = trim((string) ($cfg['success_contains'] ?? ''));
        $ok = $res->successful() && ($needle === '' || str_contains($respBody, $needle));

        if ($ok) {
            return [
                'ok'         => true,
                'message_id' => $this->extractId((string) ($cfg['message_id_path'] ?? ''), $res),
                'segments'   => $segments,
            ];
        }
        return [
            'ok'              => false,
            'code'            => $res->status(),
            'bad_credentials' => in_array($res->status(), [401, 403], true),
            'error'           => 'Gateway rejected the message (HTTP ' . $res->status() . '): ' . mb_substr($respBody, 0, 200),
        ];
    }

    /** Confirm a gateway definition is complete enough to send. */
    public static function verify(array $sendConfig): array
    {
        $url = trim((string) ($sendConfig['url'] ?? ''));
        if ($url === '') {
            return ['ok' => false, 'error' => 'Enter the gateway send URL.'];
        }
        if (! preg_match('#^https?://#i', $url)) {
            return ['ok' => false, 'error' => 'The send URL must start with http:// or https://.'];
        }
        return ['ok' => true];
    }

    // ── internals ──────────────────────────────────────────────────────────

    private function sendConfig(): array
    {
        $meta = is_array($this->sender->config?->meta_json) ? $this->sender->config->meta_json : [];
        return (array) ($meta['custom_send'] ?? []);
    }

    /** Placeholder values. Secrets come from the (decrypted) creds on the sender. */
    private function vars(string $toDigits, string $body, int $segments): array
    {
        $creds = is_object($this->sender->config) ? (array) $this->sender->config->creds() : [];
        return [
            '{to}'         => $toDigits,
            '{to_plain}'   => $toDigits,
            '{to_e164}'    => '+' . $toDigits,
            '{text}'       => $body,
            '{text_url}'   => rawurlencode($body),
            '{from}'       => $this->sender->from_number,
            '{sender_id}'  => $this->sender->sender_id ?: $this->sender->from_number,
            '{api_key}'    => (string) ($creds['api_key'] ?? $this->sender->account_sid),
            '{api_secret}' => (string) ($creds['api_secret'] ?? $this->sender->auth_token),
            '{segments}'   => (string) $segments,
        ];
    }

    private function buildHeaders(array $headers, array $vars): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            if ($k === '' || $k === null) continue;
            $out[(string) $k] = self::subst((string) $v, $vars);
        }
        return $out;
    }

    /** Pull a value out of a JSON response by a dot path ("data.id"). */
    private function extractId(string $path, $res): string
    {
        $path = trim($path);
        if ($path === '') return '';
        try {
            $json = $res->json();
            return (string) (data_get($json, $path) ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    public static function subst(string $tpl, array $vars): string
    {
        return strtr($tpl, $vars);
    }

    private static function appendQuery(string $url, string $query): string
    {
        $query = ltrim($query, '?&');
        if ($query === '') return $url;
        return $url . (str_contains($url, '?') ? '&' : '?') . $query;
    }
}
