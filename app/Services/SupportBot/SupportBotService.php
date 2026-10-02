<?php

namespace App\Services\SupportBot;

use App\Models\SupportBotLog;
use App\Support\SupportBotSettings;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates the Client Support Bot answer ladder:
 *   1. DOCS  — strong docs match ⇒ answer from docs, no AI (the common case).
 *   2. AI    — weak-but-present docs match AND AI on ⇒ RAG grounded in the docs.
 *   3. WEB   — not in docs (or AI said NO_ANSWER) AND web on ⇒ web-grounded answer.
 *   4. else  — contact / no-answer fallback.
 * Everything is admin-configured (SupportBotSettings); the user just asks.
 * Every turn is logged with the tier that answered.
 */
class SupportBotService
{
    public function __construct(
        private Retriever $retriever,
        private LocalAnswerer $local,
        private AiAnswerer $ai,
        private WebFallback $web,
    ) {
    }

    /**
     * @return array{ok: bool, engine: string, answer: string, sections: array, sources: array, score: float, matched: bool, contact?: array, disabled?: bool}
     */
    public function ask(string $question, ?int $userId = null, ?string $sessionId = null, ?string $ip = null, array $history = []): array
    {
        $question = trim($question);
        if ($question === '') {
            return ['ok' => false, 'engine' => 'none', 'answer' => 'Please type a question.', 'sections' => [], 'sources' => [], 'score' => 0.0, 'matched' => false];
        }
        if (! SupportBotSettings::enabled()) {
            return ['ok' => false, 'engine' => 'none', 'answer' => '', 'sections' => [], 'sources' => [], 'score' => 0.0, 'matched' => false, 'disabled' => true];
        }

        $cfg = SupportBotSettings::raw();

        // Greeting / chit-chat ("hi", "thanks", "ok") → a friendly prompt. Never
        // run retrieval for these — a bare "hi" used to prefix-match "history"
        // and dump the Account doc.
        if ($this->isGreeting($question)) {
            $reply = trim((string) $cfg['greeting']) !== ''
                ? (string) $cfg['greeting']
                : "Hi! Ask me a question about WaDesk — like how to connect a device or reset your password — and I'll search the help docs.";
            $out = ['ok' => true, 'engine' => 'none', 'answer' => $reply, 'sections' => [], 'sources' => [], 'score' => 0.0, 'matched' => false, 'greeting' => true];
            $this->logTurn($question, $reply, false, 0.0, 'none', null, $userId, $sessionId, $ip);
            return $out;
        }

        // Resolve follow-ups ("and if it fails?", "why?") against the thread so
        // retrieval still finds the right docs — the conversational upgrade.
        $retrievalQuery = $this->contextualize($question, $history);
        $retrieved = $this->retriever->retrieve($retrievalQuery);
        $best      = $retrieved[0]['score'] ?? 0.0;
        $hasDocCtx = $best >= (float) $cfg['docs_threshold'];

        Log::info('[SUPPORT-BOT] ask', [
            'q'          => mb_substr($question, 0, 100),
            'retrieval'  => mb_substr($retrievalQuery, 0, 100),
            'best'       => round($best, 3),
            'threshold'  => (float) $cfg['docs_threshold'],
            'hasDocCtx'  => $hasDocCtx,
            'ai_on'      => SupportBotSettings::aiEnabled(),
            'web_on'     => SupportBotSettings::webEnabled(),
            'provider'   => $cfg['ai_provider'],
            'model'      => $cfg['ai_model'],
            'top'        => collect($retrieved)->take(4)->map(fn ($r) => ($r['chunk']->heading ?: '?') . ' [src' . $r['chunk']->source_id . '] ' . round($r['score'], 2))->all(),
        ]);

        // Asking to reach a human already has a perfect answer — the contact card
        // — so the docs must clear a much higher bar to override it.
        if ($this->wantsContact($question) && $best < 0.6) {
            return $this->notFound($cfg, $best, $question, $userId, $sessionId, $ip);
        }

        $anyDocs = !empty($retrieved);

        // AI ON → the AI is the brain. Give it WHATEVER we retrieved (even chunks
        // that ranked just below the docs threshold — a "connect vibe account"
        // query where the Viber page scores 0.20 should still reach the AI) and
        // let it judge relevance: it answers grounded in the docs, or replies
        // NO_ANSWER when they genuinely don't cover it, which drops through to web.
        if ($anyDocs && SupportBotSettings::aiEnabled()) {
            $a = $this->safe(fn () => $this->ai->answer($question, $retrieved, $cfg, $history), 'ai');
            if ($a) return $this->finalize('ai', $a, $best, $question, $userId, $sessionId, $ip);
            // AI said the docs don't cover it → web (general/live), then a decent
            // docs extract, else the contact card.
            if (SupportBotSettings::webEnabled()) {
                $a = $this->safe(fn () => $this->web->answer($question, $cfg, $history), 'web');
                if ($a) return $this->finalize('web', $a, $best, $question, $userId, $sessionId, $ip);
            }
            if ($hasDocCtx) {
                return $this->finalize('docs', $this->local->answer($question, $retrieved), $best, $question, $userId, $sessionId, $ip);
            }
            return $this->notFound($cfg, $best, $question, $userId, $sessionId, $ip);
        }

        // AI OFF but a solid docs match → extractive, structured docs answer.
        if ($hasDocCtx) {
            return $this->finalize('docs', $this->local->answer($question, $retrieved), $best, $question, $userId, $sessionId, $ip);
        }

        // No usable docs → web fallback (if on), else contact card.
        if (SupportBotSettings::webEnabled()) {
            $a = $this->safe(fn () => $this->web->answer($question, $cfg, $history), 'web');
            if ($a) return $this->finalize('web', $a, $best, $question, $userId, $sessionId, $ip);
        }
        return $this->notFound($cfg, $best, $question, $userId, $sessionId, $ip);
    }

    /**
     * Turn a context-dependent follow-up into a standalone retrieval query by
     * prepending the previous USER turn. A short or pronoun/連詞-led message
     * ("and then?", "why?", "what about groups") carries no topic words, so on
     * its own it retrieves nothing useful; with the prior question attached it
     * finds the right docs — this is what makes the thread feel conversational.
     */
    private function contextualize(string $q, array $history): string
    {
        if (empty($history)) return $q;

        // A follow-up carries almost no topic of its own — "give me steps",
        // "tell me more about that one", "how?", "and the LINE one". Strip common
        // + procedural words; if <= 1 specific word remains, it depends on the
        // prior turn, so prepend the previous USER question for retrieval. A
        // self-contained question ("how do I connect a device") keeps its own
        // topic words and is left untouched.
        $generic = [
            'give', 'me', 'show', 'tell', 'more', 'about', 'the', 'how', 'do', 'does', 'to', 'can',
            'please', 'steps', 'step', 'guide', 'tutorial', 'walk', 'it', 'that', 'this', 'those',
            'these', 'and', 'also', 'then', 'why', 'what', 'which', 'for', 'one', 'info', 'information',
            'detail', 'details', 'explain', 'set', 'setup', 'get', 'where', 'you', 'your', 'i', 'my',
            'is', 'are', 'in', 'on', 'of', 'a', 'an', 'work', 'works', 'working',
        ];
        $tokens = preg_split('/[^a-z0-9]+/i', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $specific = array_filter($tokens, fn ($t) => strlen($t) >= 3 && !in_array($t, $generic, true));
        if (count($specific) > 1) return $q; // self-contained enough

        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['role'] ?? '') === 'user' && trim((string) ($history[$i]['text'] ?? '')) !== '') {
                return trim((string) $history[$i]['text']) . ' ' . $q;
            }
        }
        return $q;
    }

    /** A bare greeting / filler that should get a prompt, not a doc search. */
    private function isGreeting(string $q): bool
    {
        $s = trim(preg_replace('/\s+/', ' ', mb_strtolower(preg_replace('/[^\p{L}\s]/u', '', $q) ?? '')) ?? '');
        $greet = [
            'hi', 'hii', 'hiii', 'hello', 'helo', 'hey', 'heyy', 'yo', 'hiya', 'sup', 'wassup',
            'thanks', 'thank you', 'thankyou', 'thx', 'ty', 'ok', 'okay', 'k', 'cool', 'nice', 'great',
            'test', 'testing', 'gm', 'good morning', 'good afternoon', 'good evening',
            'hello there', 'hey there', 'morning', 'hola', 'namaste',
        ];
        return in_array($s, $greet, true);
    }

    /** Is the visitor asking to reach a human rather than about the product? */
    private function wantsContact(string $q): bool
    {
        $s = preg_replace('/\s+/', ' ', mb_strtolower(trim($q))) ?? '';
        foreach (['contact', 'get in touch', 'reach out', 'reach you', 'talk to a human',
            'talk to someone', 'speak to someone', 'customer care', 'customer support', 'call you'] as $needle) {
            if (str_contains($s, $needle)) return true;
        }
        return false;
    }

    /** Wrap a tier call so one tier's failure never breaks the ladder. */
    private function safe(callable $fn, string $tier): ?array
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            Log::warning("[SUPPORT-BOT] tier {$tier} failed: " . $e->getMessage());
            return null;
        }
    }

    private function finalize(string $engine, array $ans, float $score, string $q, ?int $userId, ?string $sessionId, ?string $ip): array
    {
        Log::info('[SUPPORT-BOT] answered via ' . $engine, ['score' => round($score, 3)]);
        $out = [
            'ok'       => true,
            'engine'   => $engine,
            'answer'   => (string) ($ans['answer'] ?? ''),
            'sections' => $ans['sections'] ?? [],
            'sources'  => $ans['sources'] ?? [],
            'score'    => round($score, 4),
            'matched'  => true,
        ];
        $out['log_id'] = $this->logTurn($q, $out['answer'], true, $score, $engine, $ans['source_id'] ?? null, $userId, $sessionId, $ip);
        return $out;
    }

    private function notFound(array $cfg, float $score, string $q, ?int $userId, ?string $sessionId, ?string $ip): array
    {
        $out = [
            'ok'       => true,
            'engine'   => 'none',
            'answer'   => (string) $cfg['no_answer'],
            'sections' => [],
            'sources'  => [],
            'score'    => round($score, 4),
            'matched'  => false,
            'contact'  => [
                'url'   => (string) $cfg['support_url'],
                'email' => (string) $cfg['support_email'],
            ],
        ];
        $out['log_id'] = $this->logTurn($q, $out['answer'], false, $score, 'none', null, $userId, $sessionId, $ip);
        return $out;
    }

    /** Persist one Q/A turn; returns the new log id so the widget can rate it. */
    private function logTurn(string $q, string $a, bool $matched, float $score, string $engine, ?int $sourceId, ?int $userId, ?string $sessionId, ?string $ip): ?int
    {
        try {
            return SupportBotLog::create([
                'user_id'    => $userId,
                'question'   => mb_substr($q, 0, 2000),
                'answer'     => mb_substr($a, 0, 8000),
                'matched'    => $matched,
                'score'      => round($score, 4),
                'engine'     => $engine,
                'source_id'  => $sourceId,
                'session_id' => $sessionId ? mb_substr($sessionId, 0, 64) : null,
                'ip'         => $ip ? mb_substr($ip, 0, 45) : null,
            ])->id;
        } catch (\Throwable $e) {
            Log::warning('[SUPPORT-BOT] log write failed: ' . $e->getMessage());
            return null;
        }
    }
}
