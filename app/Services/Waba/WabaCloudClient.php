<?php

namespace App\Services\Waba;

use App\Models\SystemSetting;
use App\Models\WaProviderConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Official WhatsApp Cloud API — advanced endpoints beyond send/receive, each
 * verified against Meta's docs (payloads noted per method). WABA only; the
 * unofficial engine and IG/FB have no equivalent. Every call is best-effort:
 * it returns ['ok'=>bool, ...] and never throws, so a Meta hiccup can't break
 * the calling flow.
 *
 * Covered (Graph base https://graph.facebook.com/{ver}):
 *   1. Read receipts + typing indicator   POST /{phone_number_id}/messages
 *   2. WhatsApp QR codes / short links     {POST,GET,DELETE} /{phone_number_id}/message_qrdls
 *   3. Business profile                    {GET,POST} /{phone_number_id}/whatsapp_business_profile
 *   4. Conversational automation           {GET,POST} .../{phone_number_id}/conversational_automation
 *   5. Two-step verification PIN           POST /{phone_number_id}  {pin}
 *   6. Messaging / conversation analytics  GET /{waba_id}?fields=analytics / conversation_analytics / pricing_analytics
 *   7. In-chat payments (order_details)    POST /{phone_number_id}/messages  interactive:order_details  (region-gated)
 *   8. Groups                              {POST,GET} /{phone_number_id}/groups  (limited availability)
 */
class WabaCloudClient
{
    private string $base;
    private string $phoneId;
    private string $wabaId;
    private string $token;

    public function __construct(WaProviderConfig $cfg)
    {
        $creds         = $cfg->creds();
        $version       = (string) SystemSetting::get('waba_graph_api_version', 'v23.0');
        $this->base    = 'https://graph.facebook.com/' . ltrim($version, '/');
        $this->token   = (string) ($creds['access_token'] ?? '');
        $this->phoneId = (string) (($cfg->meta_json['phone_number_id'] ?? null) ?: ($creds['phone_number_id'] ?? ''));
        $this->wabaId  = (string) (($cfg->meta_json['waba_id'] ?? null) ?: ($creds['waba_id'] ?? ''));
    }

    public static function forWorkspace(int $workspaceId): ?self
    {
        $cfg = WaProviderConfig::where('workspace_id', $workspaceId)
            ->where('provider', \App\Services\WorkspaceEngine::ENGINE_WABA)
            ->where('status', WaProviderConfig::STATUS_CONNECTED)
            ->orderByDesc('connected_at')
            ->first();

        return $cfg ? new self($cfg) : null;
    }

    public function isUsable(): bool { return $this->token !== '' && $this->phoneId !== ''; }
    public function hasWaba(): bool { return $this->wabaId !== ''; }

    // ── 1. Read receipt + typing indicator ───────────────────────────────
    // POST /{pnid}/messages {messaging_product, status:read, message_id,
    //   typing_indicator:{type:text}}. Marks the inbound read (blue ticks) and,
    //   with $typing, shows "typing…" for up to 25s or until the reply is sent.
    public function markRead(string $inboundMessageId, bool $typing = true): array
    {
        if ($inboundMessageId === '') return ['ok' => false, 'error' => 'no message id'];
        $body = ['messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => $inboundMessageId];
        if ($typing) $body['typing_indicator'] = ['type' => 'text'];
        return $this->post("/{$this->phoneId}/messages", $body, 'markRead');
    }

    // ── 2. QR codes / short links ────────────────────────────────────────
    public function createQrCode(string $prefilledMessage, string $format = 'PNG'): array
    {
        $fmt = strtoupper($format) === 'SVG' ? 'SVG' : 'PNG';
        return $this->post("/{$this->phoneId}/message_qrdls", [
            'prefilled_message' => $prefilledMessage,
            'generate_qr_image' => $fmt,
        ], 'createQrCode');
    }
    public function listQrCodes(): array { return $this->get("/{$this->phoneId}/message_qrdls", [], 'listQrCodes'); }
    public function deleteQrCode(string $code): array { return $this->delete("/{$this->phoneId}/message_qrdls/{$code}", 'deleteQrCode'); }

    // ── 3. Business profile ──────────────────────────────────────────────
    public function getBusinessProfile(): array
    {
        return $this->get("/{$this->phoneId}/whatsapp_business_profile", [
            'fields' => 'about,address,description,email,profile_picture_url,websites,vertical',
        ], 'getBusinessProfile');
    }
    /** @param array{about?:string,address?:string,description?:string,email?:string,websites?:array,vertical?:string,profile_picture_handle?:string} $fields */
    public function setBusinessProfile(array $fields): array
    {
        $allowed = array_intersect_key($fields, array_flip([
            'about', 'address', 'description', 'email', 'websites', 'vertical', 'profile_picture_handle',
        ]));
        $allowed['messaging_product'] = 'whatsapp';
        return $this->post("/{$this->phoneId}/whatsapp_business_profile", $allowed, 'setBusinessProfile');
    }

    // ── 4. Conversational automation (welcome + ice breakers + commands) ──
    public function getConversationalAutomation(): array
    {
        return $this->get("/{$this->phoneId}", ['fields' => 'conversational_automation'], 'getConversationalAutomation');
    }
    /**
     * @param string[] $prompts   up to 4 ice-breaker strings (≤80 chars each)
     * @param array<int,array{command_name:string,command_description:string}> $commands
     */
    public function setConversationalAutomation(bool $enableWelcome, array $prompts = [], array $commands = []): array
    {
        $prompts  = array_slice(array_values(array_filter(array_map('trim', $prompts))), 0, 4);
        $commands = array_values(array_filter($commands, fn ($c) => !empty($c['command_name'])));
        return $this->post("/{$this->phoneId}/conversational_automation", [
            'enable_welcome_message' => $enableWelcome,
            'prompts'  => $prompts,
            'commands' => $commands,
        ], 'setConversationalAutomation');
    }

    // ── 5. Two-step verification PIN ─────────────────────────────────────
    public function setTwoStepPin(string $pin): array
    {
        if (!preg_match('/^\d{6}$/', $pin)) return ['ok' => false, 'error' => 'PIN must be exactly 6 digits'];
        return $this->post("/{$this->phoneId}", ['pin' => $pin], 'setTwoStepPin');
    }

    // ── 6. Analytics ─────────────────────────────────────────────────────
    public function getMessagingAnalytics(int $startTs, int $endTs, string $granularity = 'DAY'): array
    {
        if (!$this->hasWaba()) return ['ok' => false, 'error' => 'waba_id not available'];
        $g = in_array($granularity, ['HALF_HOUR', 'DAY', 'MONTH'], true) ? $granularity : 'DAY';
        return $this->get("/{$this->wabaId}", [
            'fields' => "analytics.start({$startTs}).end({$endTs}).granularity({$g})",
        ], 'getMessagingAnalytics');
    }
    public function getConversationAnalytics(int $startTs, int $endTs, string $granularity = 'DAILY'): array
    {
        if (!$this->hasWaba()) return ['ok' => false, 'error' => 'waba_id not available'];
        $g = in_array($granularity, ['HALF_HOUR', 'DAILY', 'MONTHLY'], true) ? $granularity : 'DAILY';
        return $this->get("/{$this->wabaId}", [
            'fields' => "conversation_analytics.start({$startTs}).end({$endTs}).granularity({$g})",
        ], 'getConversationAnalytics');
    }

    // ── 7. In-chat payments — order_details interactive (region-gated) ────
    // Body skeleton per Meta; $order carries items/total/payment settings. The
    // number must be enabled for WhatsApp Pay (IN/BR/SG) — otherwise Meta 400s.
    public function sendOrderDetails(string $to, array $interactiveOrderDetails): array
    {
        return $this->post("/{$this->phoneId}/messages", [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => preg_replace('/\D+/', '', $to),
            'type'              => 'interactive',
            'interactive'       => $interactiveOrderDetails, // {type:order_details, body, action:{name:review_and_pay, parameters:{...}}}
        ], 'sendOrderDetails');
    }

    // ── 8. Groups (limited availability) ─────────────────────────────────
    public function createGroup(array $payload): array { return $this->post("/{$this->phoneId}/groups", $payload, 'createGroup'); }
    public function listGroups(): array { return $this->get("/{$this->phoneId}/groups", [], 'listGroups'); }

    // ── HTTP helpers ─────────────────────────────────────────────────────
    private function req()
    {
        return Http::withToken($this->token)->acceptJson()->timeout(25);
    }
    private function post(string $path, array $body, string $op): array { return $this->run('post', $path, $body, $op); }
    private function get(string $path, array $query, string $op): array { return $this->run('get', $path, $query, $op); }
    private function delete(string $path, string $op): array { return $this->run('delete', $path, [], $op); }

    private function run(string $method, string $path, array $data, string $op): array
    {
        if (!$this->isUsable()) return ['ok' => false, 'error' => 'WABA provider missing access_token or phone_number_id'];
        try {
            $url = $this->base . $path;
            $res = match ($method) {
                'get'    => $this->req()->get($url, $data),
                'delete' => $this->req()->delete($url),
                default  => $this->req()->post($url, $data),
            };
            $json = $res->json() ?? [];
            if ($res->successful() && !isset($json['error'])) {
                return ['ok' => true, 'data' => $json];
            }
            $msg = (string) ($json['error']['message'] ?? ('HTTP ' . $res->status()));
            Log::warning('[WABA-CLOUD] ' . $op . ' failed', ['phone_id' => $this->phoneId, 'status' => $res->status(), 'error' => $msg]);
            return ['ok' => false, 'error' => $msg, 'data' => $json];
        } catch (\Throwable $e) {
            Log::warning('[WABA-CLOUD] ' . $op . ' exception: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
