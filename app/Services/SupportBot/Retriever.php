<?php

namespace App\Services\SupportBot;

use App\Models\SupportBotChunk;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ranks support chunks against a question. Faithful port of the chatdoc
 * reference Retriever: candidate fetch via MySQL FULLTEXT (LIKE fallback),
 * then PHP re-scoring with concept/synonym groups, IDF weighting, a
 * coordination factor and a verbatim boost — normalized 0..1 so the answer-vs-
 * escalate thresholds behave consistently. Adapted to support_bot_chunks
 * (source_id, no project scoping).
 */
class Retriever
{
    /** Score kept by a chunk matching only one of several query concepts. */
    private const COORD_FLOOR = 0.35;

    /** Concept groups: a chunk matches the concept if it contains ANY variant. */
    private const SYNONYM_GROUPS = [
        ['step', 'guide', 'tutorial', 'instruction', 'walkthrough'],
        ['install', 'setup', 'deploy', 'connect'],
        ['error', 'issue', 'problem', 'bug', 'fail'],
        ['update', 'upgrade'],
        ['doc', 'documentation', 'manual'],
        ['changelog', 'release', 'feature', 'features', 'whatsnew'],
        ['requirement', 'prerequisite'],
        ['price', 'cost', 'fee', 'plan', 'billing'],
        ['delete', 'remove', 'cancel'],
        ['password', 'passcode', 'login', 'signin'],
        ['message', 'msg', 'chat', 'reply'],
        ['device', 'number', 'phone'],
    ];

    private const STOPWORDS = [
        'the', 'a', 'an', 'and', 'or', 'but', 'of', 'to', 'in', 'on', 'for',
        'is', 'are', 'was', 'were', 'be', 'been', 'do', 'does', 'did', 'how',
        'what', 'why', 'when', 'where', 'which', 'who', 'can', 'i', 'my', 'me',
        'you', 'your', 'it', 'its', 'this', 'that', 'with', 'as', 'at', 'by',
        'from', 'about', 'into', 'if', 'then', 'so', 'we', 'our', 'us', 'please',
        // Speech-act verbs framing a request, not naming a topic.
        'give', 'tell', 'show', 'explain', 'list', 'want', 'need', 'know', 'get',
    ];

    /**
     * @return array<int, array{chunk: SupportBotChunk, score: float}>
     */
    public function retrieve(string $query, int $k = 6): array
    {
        $groups = $this->terms($query);
        if (empty($groups)) return [];

        $candidates = $this->candidates($query, $groups);
        if ($candidates->isEmpty()) return [];

        $idf = $this->idfWeights($groups);
        $totalIdf = array_sum($idf) ?: 1.0;
        $normalisedQuery = $this->collapse(mb_strtolower($query));

        $scored = [];
        foreach ($candidates as $chunk) {
            $score = $this->score($groups, $idf, $totalIdf, $normalisedQuery, $chunk);
            if ($score > 0) $scored[] = ['chunk' => $chunk, 'score' => $score];
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, $k);
    }

    /** IDF per concept: log(1 + N/df) — rare concepts dominate. */
    private function idfWeights(array $groups): array
    {
        $n = max(1, DB::table('support_bot_chunks')->count());
        $weights = [];
        foreach ($groups as $i => $variants) {
            $df = DB::table('support_bot_chunks')
                ->where(function ($q) use ($variants) {
                    foreach ($variants as $v) {
                        $like = '%' . addcslashes($v, '%_\\') . '%';
                        $q->orWhereRaw('LOWER(content) LIKE ?', [$like])
                          ->orWhereRaw('LOWER(heading) LIKE ?', [$like]);
                    }
                })->count();
            $weights[$i] = log(1 + $n / max($df, 0.5));
        }
        return $weights;
    }

    private function candidates(string $query, array $groups): Collection
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $expanded = implode(' ', array_unique(array_merge(...$groups)));
            $rows = SupportBotChunk::query()
                ->whereRaw('MATCH(heading, content) AGAINST (? IN NATURAL LANGUAGE MODE)', [$expanded])
                ->orderByRaw('MATCH(heading, content) AGAINST (? IN NATURAL LANGUAGE MODE) DESC', [$expanded])
                ->limit(300)->get();
            if ($rows->isNotEmpty()) return $rows;
        }
        return SupportBotChunk::query()
            ->where(function ($q) use ($groups) {
                foreach (array_merge(...$groups) as $v) {
                    $like = '%' . addcslashes($v, '%_\\') . '%';
                    $q->orWhereRaw('LOWER(content) LIKE ?', [$like])
                      ->orWhereRaw('LOWER(heading) LIKE ?', [$like]);
                }
            })->limit(300)->get();
    }

    private function score(array $groups, array $idf, float $totalIdf, string $normalisedQuery, object $chunk): float
    {
        $content = mb_strtolower((string) $chunk->content);
        $heading = mb_strtolower((string) ($chunk->heading ?? ''));

        $matchedIdf = 0.0; $headIdf = 0.0; $freq = 0; $matchedTerms = 0;
        foreach ($groups as $i => $variants) {
            $inContent = 0; $inHeading = 0;
            foreach ($variants as $v) {
                $inContent += $this->countWord($content, $v);
                $inHeading += $this->countWord($heading, $v);
            }
            $weight = $idf[$i] ?? 0.0;
            if ($inContent > 0 || $inHeading > 0) { $matchedIdf += $weight; $matchedTerms++; }
            if ($inHeading > 0) $headIdf += $weight;
            $freq += $inContent + $inHeading;
        }
        if ($matchedIdf === 0.0) return 0.0;

        $coverage = $matchedIdf / $totalIdf;
        $headCoverage = $headIdf / $totalIdf;
        $density = $freq / ($freq + 3);
        $score = 0.68 * $coverage + 0.29 * $headCoverage + 0.03 * $density;

        $coord = $matchedTerms / max(count($groups), 1);
        $score *= self::COORD_FLOOR + (1.0 - self::COORD_FLOOR) * $coord;

        if (mb_strlen($normalisedQuery) >= 8 && str_contains($this->collapse($content), $normalisedQuery)) {
            $score = max($score, 0.92);
        }
        return min(1.0, $score);
    }

    /** @return array<int, array<int, string>> concept groups */
    private function terms(string $query): array
    {
        // Keep dotted version tokens WHOLE ("1.7", "v2.3") — a plain split turns
        // "1.7" into "1" and "7" (both dropped as too short), losing the version
        // the user actually asked about. Otherwise take alphanumeric runs.
        preg_match_all('/[a-z0-9]+(?:\.[a-z0-9]+)+|[a-z0-9]+/i', mb_strtolower($query), $m);
        $tokens = $m[0] ?? [];
        // A token is meaningful if it is >=2 chars OR carries a version dot/digit,
        // and is not a stopword.
        $keep = fn ($t) => (strlen($t) >= 2 || str_contains($t, '.') || preg_match('/\d/', $t)) && !in_array($t, self::STOPWORDS, true);
        $meaningful = array_filter($tokens, $keep);
        if (empty($meaningful)) $meaningful = array_filter($tokens, fn ($t) => strlen($t) >= 2 || str_contains($t, '.'));

        $groups = []; $seen = [];
        foreach (array_unique($meaningful) as $token) {
            $variants = $this->expand($token);
            $key = implode('|', $variants);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $groups[] = $variants;
        }
        return $groups;
    }

    /** Exact or singular-of-plural, then synonym group — never a blind prefix. */
    private function expand(string $token): array
    {
        $forms = [$token];
        if (strlen($token) > 3 && str_ends_with($token, 's')) $forms[] = substr($token, 0, -1);
        foreach (self::SYNONYM_GROUPS as $group) {
            foreach ($forms as $form) {
                if (in_array($form, $group, true)) return $group;
            }
        }
        return [$token];
    }

    /** Word-boundary prefix match (lightweight stemmer: install→installation). */
    private function countWord(string $haystack, string $term): int
    {
        if ($term === '' || $haystack === '') return 0;
        return (int) preg_match_all('/\b' . preg_quote($term, '/') . '/u', $haystack);
    }

    private function collapse(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
