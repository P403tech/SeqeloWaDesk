<?php

namespace App\Services\Widget;

use App\Models\ChatbotWidget;
use App\Models\Conversation;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Laravel → Node handoff for embedded chat-widget flows.
 *
 * Ported from EmailFlowBridge, which is the closest existing analogue: the Node
 * engine holds NO credentials and delegates every SEND back to PHP. For the
 * widget that is not a security measure but a structural fact — the widget has
 * no external API at all. A "send" is an outbound row in WaDesk's own inbox,
 * which the visitor's widget picks up on its next /history poll. Only PHP can
 * write that row, so only PHP can send.
 *
 * Node answers SYNCHRONOUSLY whether a flow consumed the message (`consumed`),
 * which lets ChatbotWidgetPublicController skip its keyword rules AND the AI
 * assistant so the visitor never gets two answers to one message.
 *
 * The session key is the widget row id + the WaDesk conversation id.
 */
class WidgetFlowBridge
{
    /**
     * @param  array|null  $flow  flow_data — REQUIRED to START, omitted to RESUME.
     * @return bool  true when a flow consumed the message.
     */
    public static function handoff(
        ChatbotWidget $widget,
        Conversation $convo,
        string $text,
        ?array $flow = null,
        $flowId = null,
        array $vars = []
    ): bool {
        $nodeUrl = (string) (SystemSetting::get('baileys_server_url', '') ?: env('SERVER_URL', ''));
        if ($nodeUrl === '' || ! $convo->id) {
            return false;
        }

        $appDomain = rtrim((string) (config('app.url') ?: url('/')), '/');

        try {
            $r = Http::withHeaders(['X-Node-Token' => node_token()])
                ->timeout(15)
                ->acceptJson()
                ->post(rtrim($nodeUrl, '/').'/api/webchat-flow/inbound', array_filter([
                    'widgetId'       => $widget->id,
                    'conversationId' => (string) $convo->id,
                    'workspaceId'    => $widget->workspace_id,
                    'text'           => $text,
                    'flow'           => $flow,
                    'flowId'         => $flowId,
                    'vars'           => (object) $vars,
                    'appDomain'      => $appDomain,
                    // Node stores this per-session so flow-send / flow-node
                    // callbacks reach the right PHP install with the right token.
                    'auth'           => ['base' => $appDomain, 'token' => node_token()],
                ], fn ($v) => $v !== null));

            if (! $r->successful()) {
                Log::warning('[WIDGET-FLOW-BRIDGE] Node '.$r->status().': '.mb_substr((string) $r->body(), 0, 150));

                return false;
            }

            return (bool) $r->json('consumed', false);
        } catch (\Throwable $e) {
            // Node unreachable must NOT cost the visitor their reply — returning
            // false lets the caller fall through to keyword rules and the AI
            // assistant, so the widget degrades to its pre-flow behaviour.
            Log::warning('[WIDGET-FLOW-BRIDGE] Node unreachable: '.mb_substr($e->getMessage(), 0, 150));

            return false;
        }
    }
}
