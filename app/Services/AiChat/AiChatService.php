<?php

namespace App\Services\AiChat;

use App\Models\AiChatAssistant;
use App\Models\AiTrainingSource;
use App\Models\Conversation;
use App\Models\InboxMessage;
use App\Models\Message;
use App\Services\AiAgentService;
use Illuminate\Support\Facades\Log;

/**
 * Single-shot text reply for chat assistants. Wraps AiAgentService's
 * provider router so we don't duplicate the OpenAI / Anthropic / Gemini
 * branching, then stitches in the assistant's persona and any training
 * sources (raw concat for v1 — RAG can swap the contextFor() body
 * later without touching callers).
 *
 * Used by the public chatbot-widget endpoint and any future text-AI
 * channel (e.g. WhatsApp inbound auto-reply).
 */
class AiChatService
{
    public function __construct(private AiAgentService $provider)
    {
    }

    /**
     * Generate a reply for the assistant given the visitor's new
     * message and prior conversation. Returns the model's text, or
     * the assistant's configured fallback message on failure.
     */
    public function reply(AiChatAssistant $assistant, Conversation $convo, string $visitorMessage): string
    {
        $system  = $this->systemPrompt($assistant);
        $context = $this->contextFor($assistant);
        if ($context !== '') {
            $system .= "\n\n--- Knowledge base ---\n" . $context . "\n--- End knowledge base ---";
        }
        $web = \App\Services\Ai\AgentWebsiteContext::promptBlock($assistant);
        if ($web !== '') {
            $system .= "\n\n--- Website pages to share ---\n".$web."\n--- End website pages ---";
        }

        try {
            $lang = \App\Services\Ai\CustomerLanguage::detectFromText(trim($visitorMessage));
            $fallback = strtolower(trim((string) ($assistant->language ?? 'en'))) ?: 'en';
            $system .= \App\Services\Ai\CustomerLanguage::promptBlock($lang, $fallback);
        } catch (\Throwable $e) { /* language hint is best-effort */ }

        // Last ~20 turns of history (capped on character budget so we
        // don't blow the context window on chatty threads). Excludes
        // the freshly-stored visitor message — that goes in userPrompt
        // so the model sees it as the latest turn explicitly.
        // Read from inbox_messages: the widget now persists both the
        // inbound + AI-outbound bubbles there (so the team inbox can see
        // them), so that's where the transcript lives.
        $history = InboxMessage::query()
            ->where('conversation_id', $convo->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->reverse()
            ->values();

        $lines = [];
        $charBudget = 6000;
        foreach ($history as $m) {
            $role = $m->direction === 'in' ? 'Visitor' : 'Assistant';
            $line = "$role: " . trim((string) $m->body);
            if (mb_strlen($line) === 0) continue;
            $lines[] = $line;
            $charBudget -= mb_strlen($line);
            if ($charBudget <= 0) break;
        }
        $transcript = implode("\n", $lines);

        $user = ($transcript !== '' ? "Conversation so far:\n$transcript\n\n" : '')
              . "Visitor just said:\n" . trim($visitorMessage)
              . "\n\nReply briefly and helpfully. Plain text only, no role prefix.";

        $reply = $this->provider->callProvider(
            provider:     (string) $assistant->ai_provider,
            model:        (string) $assistant->ai_model,
            workspaceId:  (int) $assistant->workspace_id,
            systemPrompt: $system,
            userPrompt:   $user,
            maxTokens:    (int) ($assistant->reply_max_tokens ?: 400),
            temperature:  (float) ($assistant->temperature ?? 0.7),
        );

        if (!$reply || trim($reply) === '') {
            Log::warning('[AI-CHAT] empty reply, using fallback', [
                'assistant_id' => $assistant->id,
                'conv_id'      => $convo->id,
            ]);
            return (string) ($assistant->fallback_message
                ?: "Sorry, I couldn't generate a reply right now. A team member will follow up shortly.");
        }
        return trim($reply);
    }

    /**
     * Compose the system prompt: persona + tone + language + handoff hint.
     */
    private function systemPrompt(AiChatAssistant $assistant): string
    {
        $base = trim((string) $assistant->system_prompt) ?: 'You are a helpful website chatbot.';
        $tone = trim((string) $assistant->tone) ?: 'helpful';
        $lang = trim((string) $assistant->language) ?: 'en';

        $out  = $base . "\n";
        $out .= "Speak in a $tone tone.\n";
        $out .= "Always reply in the same language the visitor is using. If they switch, switch with them. Never default to English unless they wrote in English. Fallback if their message has no readable language: $lang.\n";
        $out .= "When Knowledge includes Live URL pages, answer from that page text and share the real URL. Never invent links.\n";
        $out .= "Keep replies short — chat-style, not essay-style. No role prefixes like \"Assistant:\".";

        if ($assistant->handoff_enabled && !empty($assistant->handoff_keyword)) {
            $out .= "\nIf the visitor asks to talk to a human, or says \"" . $assistant->handoff_keyword
                  . "\", reply with: " . (trim((string) $assistant->handoff_message) ?: 'A team member will join shortly.');
        }
        return $out;
    }

    /**
     * Concatenated training material for this assistant. Pulls every
     * `ready` source either scoped to this assistant or workspace-wide
     * (assistant_id NULL). Hard-capped at 12k characters so we never
     * blow the context window — first-in-row order wins.
     */
    public function contextFor(AiChatAssistant $assistant): string
    {
        $rows = AiTrainingSource::query()
            ->where('workspace_id', $assistant->workspace_id)
            ->where(function ($q) use ($assistant) {
                $q->whereNull('assistant_id')->orWhere('assistant_id', $assistant->id);
            })
            ->where('status', 'ready')
            ->orderBy('id')
            ->get();

        $parts = [];
        $budget = 12000;
        foreach ($rows as $r) {
            $text = trim($r->renderedText());
            if ($text === '') continue;
            $chunk = "[" . $r->label . "]";
            if ($r->kind === 'url' && trim((string) $r->url) !== '') {
                $chunk .= "\nURL: " . trim((string) $r->url);
            }
            $chunk .= "\n" . $text;
            $parts[] = mb_substr($chunk, 0, max(500, $budget));
            $budget -= mb_strlen($chunk);
            if ($budget <= 0) break;
        }
        return implode("\n\n", $parts);
    }
}
