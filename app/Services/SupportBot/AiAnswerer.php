<?php

namespace App\Services\SupportBot;

use App\Models\SupportBotSource;
use App\Services\AiAgentService;
use Illuminate\Support\Facades\Log;

/**
 * Escalation tier 2: answer with the admin's LLM, but GROUNDED in the retrieved
 * docs ("fetch most of the details from the doc"). Only invoked when the docs
 * match is weak-but-present and the admin has turned AI on. Returns null when
 * the provider errors OR the model reports the docs don't cover the question
 * (the [[NO_ANSWER]] sentinel) so the caller can escalate to web / fall back.
 */
class AiAnswerer
{
    private const CONTEXT_BUDGET = 7000;
    private const SENTINEL       = '[[NO_ANSWER]]';

    public function __construct(private AiAgentService $provider)
    {
    }

    /**
     * @param array<int, array{chunk: \App\Models\SupportBotChunk, score: float}> $retrieved
     * @param array<string, mixed> $cfg SupportBotSettings::raw()
     * @param array<int, array{role: string, text: string}> $history prior turns
     * @return array{answer: string, sections: array, sources: array<int, string>, source_id: ?int}|null
     */
    public function answer(string $query, array $retrieved, array $cfg, array $history = []): ?array
    {
        if (empty($retrieved)) return null;

        $budget = self::CONTEXT_BUDGET;
        $parts  = [];
        $sids   = [];
        foreach ($retrieved as $r) {
            if ($budget <= 0) break;
            $c = $r['chunk'];
            $block = ($c->heading ? '## ' . $c->heading . "\n" : '') . $c->content;
            $parts[] = mb_substr($block, 0, $budget);
            $budget -= mb_strlen($block);
            $sids[$c->source_id] = true;
        }
        $context = implode("\n\n", $parts);

        // Conversational + grounded: talk WITH the user (using the thread so
        // far), but only from the docs — invent nothing, and bail with the
        // sentinel when the docs don't cover it so the ladder can escalate.
        $system = "You are the WaDesk help-desk assistant, chatting with a logged-in user inside the app. "
            . "Reply in a warm, natural, conversational tone — like a helpful teammate, not a manual. Keep it "
            . "concise and use the user's own words. Use the DOCUMENTATION below and the conversation so far to "
            . "answer, and take follow-up questions in context. Do NOT invent features, steps, prices or links "
            . "that aren't in the documentation. If the documentation does not contain the answer, reply with "
            . "exactly " . self::SENTINEL . " and nothing else.\n\n"
            . "--- DOCUMENTATION ---\n" . $context . "\n--- END DOCUMENTATION ---";

        $transcript = $this->transcript($history);
        $user = ($transcript !== '' ? "Conversation so far:\n" . $transcript . "\n\n" : '')
            . "User just asked:\n" . trim($query) . "\n\nReply conversationally.";

        $srcLabels = SupportBotSource::whereIn('id', array_keys($sids))->pluck('label')->all();
        Log::info('[SUPPORT-BOT][AI] calling provider', [
            'provider' => $cfg['ai_provider'],
            'model'    => $cfg['ai_model'],
            'ctx_chunks' => count($retrieved),
            'ctx_sources' => $srcLabels,
        ]);

        // Reuse the platform's admin AI keys (workspaceId 0 → admin global key
        // via AiKeyResolver). Retry once on a transient null (provider 503/429/
        // timeout — common on Gemini preview models) so a hiccup doesn't drop the
        // answer to the web tier or contact card.
        // 2048 tokens: Gemini 3.x are THINKING models where maxOutputTokens covers
        // internal reasoning too — an 800 budget got eaten by thinking and cut the
        // visible answer mid-sentence. This leaves room for reasoning + a full reply.
        $raw = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $raw = $this->provider->callProvider(
                (string) $cfg['ai_provider'], (string) $cfg['ai_model'], 0, $system, $user, 2048, 0.3,
            );
            if ($raw !== null && trim((string) $raw) !== '') break;
            if ($attempt < 2) usleep(1300000); // 1.3s backoff before the single retry
        }

        $reply = trim((string) $raw);
        if ($raw === null || $reply === '') {
            // Null/empty almost always means: no admin key for this provider, a
            // bad model id, or the provider API errored. Check laravel.log above
            // this line for the AI-AGENT error with the HTTP status.
            Log::warning('[SUPPORT-BOT][AI] provider returned NULL/empty — no admin key, bad model, or API error', [
                'provider' => $cfg['ai_provider'], 'model' => $cfg['ai_model'],
            ]);
            return null;
        }
        if (str_contains($reply, self::SENTINEL)) {
            Log::info('[SUPPORT-BOT][AI] model replied NO_ANSWER — the retrieved docs did not cover the question', [
                'ctx_sources' => $srcLabels,
            ]);
            return null;
        }
        Log::info('[SUPPORT-BOT][AI] answered', ['len' => mb_strlen($reply), 'head' => mb_substr($reply, 0, 140)]);

        return [
            'answer'    => $reply,
            'sections'  => [],
            // Same shape the docs path returns so the widget renders it: {title, heading}.
            'sources'   => array_map(fn ($l) => ['title' => $l, 'heading' => null], array_values($srcLabels)),
            'source_id' => $retrieved[0]['chunk']->source_id ?? null,
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
