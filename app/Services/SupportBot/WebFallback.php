<?php

namespace App\Services\SupportBot;

use App\Services\AiAgentService;

/**
 * Escalation tier 3 (last resort): when a question is NOT covered by the admin's
 * docs, answer from the web. v1 routes to a web-grounded model (e.g. Perplexity
 * — an OpenAI-compatible provider WaDesk already supports) using the admin's web
 * key/model. The answer is flagged as a web result (not doc-authoritative) so
 * the widget can label it. Returns null on failure so the caller shows the
 * contact fallback.
 */
class WebFallback
{
    public function __construct(private AiAgentService $provider)
    {
    }

    /**
     * @param array<string, mixed> $cfg SupportBotSettings::raw()
     * @param array<int, array{role: string, text: string}> $history prior turns
     * @return array{answer: string, sections: array, sources: array, source_id: ?int}|null
     */
    public function answer(string $query, array $cfg, array $history = []): ?array
    {
        $system = "You are the WaDesk help-desk assistant, chatting with a logged-in user. Their question "
            . "was NOT found in our internal help docs, so answer from up-to-date general/web knowledge. Keep "
            . "a warm, conversational tone, be concise and practical, take follow-ups in context, and make "
            . "clear this is general guidance rather than WaDesk-specific documentation.";

        $transcript = $this->transcript($history);
        $user = ($transcript !== '' ? "Conversation so far:\n" . $transcript . "\n\n" : '')
            . "User just asked:\n" . trim($query) . "\n\nReply conversationally.";

        // Reuse the admin AI keys (workspaceId 0 → admin global key). 2048 tokens
        // leaves room for Gemini's thinking budget + a full answer; retry once on a
        // transient null (503/429/timeout).
        $reply = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $reply = $this->provider->callProvider(
                (string) $cfg['ai_provider'], (string) $cfg['ai_model'], 0, $system, $user, 2048, 0.3,
            );
            if ($reply !== null && trim((string) $reply) !== '') break;
            if ($attempt < 2) usleep(1300000);
        }

        $reply = trim((string) $reply);
        if ($reply === '') return null;

        return [
            'answer'    => $reply,
            'sections'  => [],
            'sources'   => [],
            'source_id' => null,
        ];
    }

    /** Last ~6 turns as "User:/Assistant:" lines, char-capped. */
    private function transcript(array $history): string
    {
        $turns = array_slice($history, -6);
        $lines = [];
        $budget = 2500;
        foreach ($turns as $t) {
            $role = (($t['role'] ?? '') === 'user') ? 'User' : 'Assistant';
            $text = trim((string) ($t['text'] ?? ''));
            if ($text === '') continue;
            $line = $role . ': ' . $text;
            $lines[] = $line;
            $budget -= mb_strlen($line);
            if ($budget <= 0) break;
        }
        return implode("\n", $lines);
    }
}
