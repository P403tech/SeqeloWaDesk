<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\ChatbotWidget;
use App\Models\Conversation;
use App\Models\InboxMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Laravel callbacks for the Node chat-widget flow engine
 * (node/services/webchatFlowService.js). Ported from EmailFlowNodeController,
 * following the per-channel pattern the codebase already uses.
 *
 * The widget has no external messaging API, so "send" here is not a bridge call
 * — it is simply an outbound row in WaDesk's own inbox. The visitor's widget
 * picks it up on its next /history poll, exactly like a team-inbox reply. That
 * is why the send MUST come back to PHP: Node cannot write to this database.
 *
 * All endpoints are X-Node-Token guarded.
 */
class WidgetFlowNodeController extends Controller
{
    private function unauthorized(Request $request): bool
    {
        $expected = (string) node_token();

        return $expected === '' || ! hash_equals($expected, (string) $request->header('X-Node-Token', ''));
    }

    /** POST /api/widget/flow-send — write a flow message onto the widget thread. */
    public function send(Request $request): JsonResponse
    {
        if ($this->unauthorized($request)) {
            return response()->json(['ok' => false], 401);
        }

        $widgetId = (int) $request->input('widgetId', 0);
        $convId   = (int) $request->input('conversationId', 0);
        $widget   = $widgetId > 0 ? ChatbotWidget::find($widgetId) : null;
        $convo    = $convId > 0 ? Conversation::find($convId) : null;

        if (! $widget || ! $convo) {
            return response()->json(['ok' => false, 'error' => 'widget/conversation missing']);
        }
        // Cross-tenant guard: the conversation must belong to the widget's own
        // workspace. Node is trusted but this endpoint takes ids off the wire.
        if ((int) $convo->workspace_id !== (int) $widget->workspace_id) {
            Log::warning('[WIDGET-FLOW-NODE] conversation/widget workspace mismatch', [
                'widget' => $widget->id, 'conversation' => $convo->id,
            ]);

            return response()->json(['ok' => false, 'error' => 'workspace mismatch']);
        }
        // A paused/removed widget must stop talking, same as the public endpoint.
        if ((string) $widget->status !== 'active') {
            return response()->json(['ok' => false, 'error' => 'widget not active']);
        }

        $kind = (string) $request->input('kind', 'text');
        $text = (string) $request->input('text', '');
        $body = $text;
        $meta = [
            'widget_id' => $widget->id,
            'source'    => 'flow',
            'channel'   => 'chatbot_widget',
        ];

        if ($kind === 'menu') {
            // Buttons render as a numbered list in the bubble; the Node engine
            // resumes on a reply matching the label OR the 1-based number, so
            // the numbering here is load-bearing, not decoration.
            $lines = [];
            $opts  = [];
            $i = 0;
            foreach ((array) $request->input('options', []) as $o) {
                $title = trim(is_array($o) ? (string) ($o['title'] ?? $o['content'] ?? '') : (string) $o);
                if ($title !== '') {
                    $opts[]  = $title;
                    $lines[] = (++$i).'. '.mb_substr($title, 0, 200);
                }
            }
            if ($lines) {
                $body = trim($text."\n\n".implode("\n", $lines));
                // Kept structured too, so the widget UI can render real buttons
                // later without changing the engine or this contract.
                $meta['options'] = $opts;
            }
        } elseif ($kind === 'media') {
            $url = trim((string) $request->input('mediaUrl', ''));
            if ($url !== '') {
                $meta['media_url']  = $url;
                $meta['media_kind'] = (string) $request->input('mediaKind', 'image');
                $body = trim(($text !== '' ? $text."\n\n" : '').$url);
            }
        }

        if (trim($body) === '') {
            return response()->json(['ok' => false, 'error' => 'empty body']);
        }

        try {
            $msg = InboxMessage::create([
                'conversation_id' => $convo->id,
                'user_id'         => $widget->user_id,
                'direction'       => 'out',
                'from_number'     => 'widget-'.$widget->id,
                'to_number'       => (string) data_get($convo->routing_meta, 'visitor_uuid', ''),
                'body'            => $body,
                'status'          => 'sent',
                'meta'            => $meta,
                'sent_at'         => now(),
            ]);
            $convo->forceFill([
                'preview'          => Str::limit($body, 120),
                'last_message_at'  => now(),
                'last_outbound_at' => now(),
            ])->save();

            return response()->json(['ok' => true, 'message_id' => $msg->id]);
        } catch (\Throwable $e) {
            Log::warning('[WIDGET-FLOW-NODE] flow-send failed: '.$e->getMessage(), [
                'widget' => $widget->id, 'conversation' => $convo->id,
            ]);

            return response()->json(['ok' => false, 'error' => 'write failed']);
        }
    }

    /** POST /api/widget/flow-node — resolve a smart node (ai / webhook). */
    public function node(Request $request): JsonResponse
    {
        if ($this->unauthorized($request)) {
            return response()->json(['ok' => false], 401);
        }
        $type = (string) ($request->input('action') ?: $request->input('type', ''));
        if ($type === 'ai') {
            return $this->handleAi($request);
        }
        if ($type === 'webhook') {
            return $this->handleWebhook($request);
        }

        // Unknown node types return ok with empty text rather than an error so
        // the flow keeps walking instead of dying mid-conversation.
        return response()->json(['ok' => true, 'type' => $type, 'text' => '']);
    }

    // -----------------------------------------------------------------

    private function subst(string $s, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/', function ($m) use ($vars) {
            $v = $vars[$m[1]] ?? '';

            return is_scalar($v) ? (string) $v : '';
        }, $s);
    }

    private function handleAi(Request $request): JsonResponse
    {
        $node = (array) $request->input('node', []);
        $vars = (array) $request->input('vars', []);
        $wsId = (int) $request->input('workspaceId', 0);

        $model = trim((string) ($node['model'] ?? $node['aiModel'] ?? '')) ?: 'gpt-4o-mini';
        $ml = strtolower($model);
        $provider = str_starts_with($ml, 'claude') ? 'anthropic'
            : (str_starts_with($ml, 'gemini') ? 'gemini'
            : ((str_starts_with($ml, 'mistral') || str_starts_with($ml, 'ministral') || str_starts_with($ml, 'open-mistral') || str_starts_with($ml, 'open-mixtral')) ? 'mistral' : 'openai'));

        $system = trim((string) ($node['system'] ?? $node['systemPrompt'] ?? $node['prompt'] ?? ''))
            ?: 'You are a helpful assistant replying inside a website chat widget. Be brief and conversational.';
        $system = $this->subst($system, $vars);

        $assistantId = (int) ($node['assistantId'] ?? $node['assistant_id'] ?? $node['knowledgeBaseId'] ?? 0);
        if ($assistantId > 0 && $wsId > 0) {
            try {
                $assistant = \App\Models\AiChatAssistant::where('workspace_id', $wsId)->find($assistantId);
                if ($assistant) {
                    $kb = app(\App\Services\AiChat\AiChatService::class)->contextFor($assistant);
                    if (trim($kb) !== '') {
                        $system .= "\n\n--- Knowledge base ---\n".$kb."\n--- End knowledge base ---";
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[WIDGET-FLOW-NODE] ai knowledge-base inject failed: '.$e->getMessage());
            }
        }

        $userPrompt = $this->subst((string) ($node['userPrompt'] ?? $node['user_prompt'] ?? ''), $vars);
        if (trim($userPrompt) === '') {
            $userPrompt = (string) ($vars['text'] ?? $vars['user_message'] ?? $vars['last_message'] ?? '');
        }

        $reply = (string) (app(\App\Services\AiAgentService::class)->callProvider(
            provider: $provider, model: $model, workspaceId: $wsId,
            systemPrompt: $system, userPrompt: $userPrompt,
            maxTokens: (int) ($node['maxTokens'] ?? $node['max_tokens'] ?? 350),
            temperature: (float) ($node['temperature'] ?? 0.7), jsonMode: false,
        ) ?? '');

        return response()->json(['ok' => true, 'type' => 'ai', 'reply' => $reply, 'text' => $reply]);
    }

    private function handleWebhook(Request $request): JsonResponse
    {
        $node = (array) $request->input('node', []);
        $vars = (array) $request->input('vars', []);
        $url  = $this->subst(trim((string) ($node['url'] ?? '')), $vars);
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return response()->json(['ok' => false, 'type' => 'webhook', 'error' => 'invalid url']);
        }

        $method  = strtolower((string) ($node['method'] ?? 'post'));
        $payload = (array) ($node['payload'] ?? $node['body'] ?? []);
        foreach ($payload as $k => $v) {
            if (is_string($v)) {
                $payload[$k] = $this->subst($v, $vars);
            }
        }

        try {
            $r = Http::timeout(20)->acceptJson();
            $resp = $method === 'get' ? $r->get($url, $payload) : $r->post($url, $payload);

            return response()->json([
                'ok'     => $resp->successful(),
                'type'   => 'webhook',
                'status' => $resp->status(),
                'json'   => is_array($resp->json()) ? $resp->json() : [],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[WIDGET-FLOW-NODE] webhook failed: '.$e->getMessage());

            return response()->json(['ok' => false, 'type' => 'webhook', 'error' => 'request failed']);
        }
    }
}
