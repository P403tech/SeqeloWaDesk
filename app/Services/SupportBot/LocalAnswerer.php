<?php

namespace App\Services\SupportBot;

use App\Models\SupportBotChunk;
use App\Models\SupportBotSource;

/**
 * Composes a docs-only answer (no LLM) from retrieved chunks. Faithful port of
 * the chatdoc LocalAnswerGenerator: lead with the best chunk, continue through
 * the SAME source's following sections (so a "follow these steps" intro is
 * joined to the steps), then append the strongest section of up to two related
 * sources whose heading actually names something the question asked about.
 * Returns structured `sections` [{heading, lines[], related, source}] so the
 * widget renders headings/bullets instead of one raw block.
 */
class LocalAnswerer
{
    private const BUDGET        = 1800;
    private const RELATED_DOCS  = 2;
    private const RELATED_RATIO = 0.6;

    /**
     * @param array<int, array{chunk: SupportBotChunk, score: float}> $retrieved
     * @return array{answer: string, sections: array, sources: array, source_id: ?int}
     */
    public function answer(string $query, array $retrieved): array
    {
        if (empty($retrieved)) {
            return ['answer' => '', 'sections' => [], 'sources' => [], 'source_id' => null];
        }
        $chunk = $retrieved[0]['chunk'];

        [$answer, $sections] = $this->composeSection($chunk);
        [$answer, $sections] = $this->addRelated($answer, $sections, $retrieved, $query);

        return [
            'answer'    => $answer,
            'sections'  => $sections,
            'sources'   => $this->sources($retrieved),
            'source_id' => (int) $chunk->source_id,
        ];
    }

    private function composeSection(object $chunk): array
    {
        $budget = self::BUDGET;
        $answer = $this->trimToSentence(trim((string) $chunk->content), $budget);
        $sections = [['heading' => $chunk->heading ?: null, 'lines' => $this->lines($answer), 'related' => false, 'source' => null]];

        $following = SupportBotChunk::query()
            ->where('source_id', $chunk->source_id)
            ->where('position', '>', (int) $chunk->position)
            ->orderBy('position')->get();

        foreach ($following as $next) {
            $piece = trim((string) $next->content);
            if ($piece === '') continue;
            $heading = filled($next->heading) ? (string) $next->heading : null;
            $block = ($heading !== null ? $heading . "\n" : '') . $piece;
            if (mb_strlen($answer) + mb_strlen($block) + 2 > $budget) break;
            $answer .= "\n\n" . $block;
            $sections[] = ['heading' => $heading, 'lines' => $this->lines($piece), 'related' => false, 'source' => null];
        }
        return [$answer, $sections];
    }

    private function addRelated(string $answer, array $sections, array $retrieved, string $query): array
    {
        if (self::RELATED_DOCS < 1 || count($retrieved) < 2) return [$answer, $sections];

        $budget = self::BUDGET;
        $minScore = self::RELATED_RATIO * (float) $retrieved[0]['score'];

        $labels = SupportBotSource::whereIn('id', array_map(fn ($i) => (int) $i['chunk']->source_id, $retrieved))
            ->pluck('label', 'id');

        $seen = [(int) $retrieved[0]['chunk']->source_id => true];
        $added = 0;
        foreach (array_slice($retrieved, 1) as $item) {
            if ($added >= self::RELATED_DOCS) break;
            $chunk = $item['chunk'];
            $sid = (int) $chunk->source_id;
            if (isset($seen[$sid]) || (float) $item['score'] < $minScore) continue;
            $piece = trim((string) $chunk->content);
            if ($piece === '') continue;
            $title = (string) ($labels[$sid] ?? '');
            $heading = filled($chunk->heading) ? (string) $chunk->heading : $title;
            if (! $this->namesQuery($heading . ' ' . $title, $query)) continue;
            $block = $heading . "\n" . $piece;
            if (mb_strlen($answer) + mb_strlen($block) + 2 > $budget) break;
            $answer .= "\n\n" . $block;
            $sections[] = ['heading' => $heading, 'lines' => $this->lines($piece), 'related' => true, 'source' => $title];
            $seen[$sid] = true;
            $added++;
        }
        return [$answer, $sections];
    }

    private function namesQuery(string $heading, string $query): bool
    {
        $heading = mb_strtolower($heading);
        $skip = ['how', 'what', 'why', 'when', 'where', 'the', 'and', 'for', 'you', 'your',
            'can', 'does', 'did', 'are', 'was', 'with', 'from', 'that', 'this', 'set', 'use',
            'get', 'give', 'tell', 'show', 'need', 'want', 'about', 'into', 'out'];
        foreach (preg_split('/[^a-z0-9]+/i', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (mb_strlen($token) < 3 || in_array($token, $skip, true)) continue;
            if (preg_match('/\b' . preg_quote($token, '/') . '/u', $heading)) return true;
        }
        return false;
    }

    /** @return array<int, string> */
    private function lines(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\n+/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') $lines[] = $line;
        }
        return $lines;
    }

    /** @return array<int, array{title: string, heading: ?string}> */
    private function sources(array $retrieved): array
    {
        $sids = [];
        foreach ($retrieved as $item) $sids[] = (int) $item['chunk']->source_id;
        $sids = array_slice(array_values(array_unique($sids)), 0, 3);
        $labels = SupportBotSource::whereIn('id', $sids)->pluck('label', 'id');

        $out = [];
        foreach ($retrieved as $item) {
            $chunk = $item['chunk'];
            $id = (int) $chunk->source_id;
            if (! isset($labels[$id])) continue;
            $key = $id . '|' . ($chunk->heading ?? '');
            $out[$key] = ['title' => (string) $labels[$id], 'heading' => $chunk->heading ? (string) $chunk->heading : null];
            if (count($out) >= 3) break;
        }
        return array_values($out);
    }

    private function trimToSentence(string $text, int $limit): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $limit) return $text;
        $slice = mb_substr($text, 0, $limit);
        if (preg_match('/^(.*[.!?])\s/us', $slice, $m) && mb_strlen($m[1]) >= (int) ($limit * 0.5)) {
            return trim($m[1]);
        }
        $lastSpace = mb_strrpos($slice, ' ');
        if ($lastSpace !== false && $lastSpace >= (int) ($limit * 0.5)) {
            $slice = mb_substr($slice, 0, $lastSpace);
        }
        return trim($slice) . '…';
    }
}
