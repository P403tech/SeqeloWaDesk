<?php

namespace App\Services\AiChat;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Live web lookups for the AI. One provider + key set by the admin
 * (Channel settings > AI web search): Tavily (default), SerpAPI or Brave.
 *
 * Used by the Call Flow "Search web" node and, opt-in per agent, by chat AI
 * agents (team inbox, every channel, flow Chatbot node) and website chat
 * assistants. Chat agents work without provider tool-calling: the model is
 * told it may answer with a single "SEARCH_WEB: <query>" line, we run that
 * search, then ask once more with the results in the prompt. So a reply that
 * doesn't need the internet costs nothing extra.
 */
class WebSearchService
{
    public const MARKER = 'SEARCH_WEB:';

    public function configured(): bool
    {
        return trim((string) SystemSetting::get('web_search_key', '')) !== '';
    }

    public function provider(): string
    {
        $p = strtolower((string) SystemSetting::get('web_search_provider', 'tavily'));
        return in_array($p, ['tavily', 'serpapi', 'brave'], true) ? $p : 'tavily';
    }

    /**
     * Run a search and return a short plain-text digest (max 3000 chars), or
     * '' on no key / no results / any failure. Never throws. Results are
     * cached for 30 minutes so the same question from many customers costs
     * one lookup. $workspaceId > 0 applies the per-workspace daily cap.
     */
    public function search(string $query, int $max = 5, int $workspaceId = 0): string
    {
        $q   = trim(mb_substr($query, 0, 300));
        $key = (string) SystemSetting::get('web_search_key', '');
        if ($q === '' || $key === '') return '';
        $max = max(1, min(10, $max));
        $provider = $this->provider();

        $cacheKey = 'websearch:' . md5($provider . '|' . mb_strtolower($q) . '|' . $max);
        $hit = Cache::get($cacheKey);
        if (is_string($hit)) return $hit;

        if ($workspaceId > 0 && !$this->underDailyCap($workspaceId)) {
            Log::info('[WEB-SEARCH] daily cap reached', ['workspace_id' => $workspaceId]);
            return '';
        }

        try {
            if ($provider === 'serpapi') {
                $r = Http::timeout(8)->get('https://serpapi.com/search.json', ['q' => $q, 'api_key' => $key, 'num' => $max]);
                $items = array_slice((array) ($r->json('organic_results') ?? []), 0, $max);
                $text  = collect($items)->map(fn ($i) => $this->line($i['title'] ?? '', $i['snippet'] ?? '', $i['link'] ?? ''))->implode("\n");
            } elseif ($provider === 'brave') {
                $r = Http::withHeaders(['X-Subscription-Token' => $key, 'Accept' => 'application/json'])
                    ->timeout(8)->get('https://api.search.brave.com/res/v1/web/search', ['q' => $q, 'count' => $max]);
                $items = array_slice((array) ($r->json('web.results') ?? []), 0, $max);
                $text  = collect($items)->map(fn ($i) => $this->line($i['title'] ?? '', strip_tags((string) ($i['description'] ?? '')), $i['url'] ?? ''))->implode("\n");
            } else { // tavily (built for AI agents)
                $r = Http::timeout(8)->post('https://api.tavily.com/search', [
                    'api_key' => $key, 'query' => $q, 'max_results' => $max, 'include_answer' => true,
                ]);
                $answer = trim((string) $r->json('answer', ''));
                $items  = array_slice((array) ($r->json('results') ?? []), 0, $max);
                $text   = trim(($answer !== '' ? 'Summary: ' . $answer . "\n" : '')
                    . collect($items)->map(fn ($i) => $this->line($i['title'] ?? '', $i['content'] ?? '', $i['url'] ?? ''))->implode("\n"));
            }
            if (!$r->successful()) {
                Log::warning('[WEB-SEARCH] provider HTTP ' . $r->status(), ['provider' => $provider, 'body' => mb_substr((string) $r->body(), 0, 300)]);
                return '';
            }
            $text = mb_substr(trim((string) $text), 0, 3000);
            if ($text !== '') Cache::put($cacheKey, $text, now()->addMinutes(30));
            Log::info('[WEB-SEARCH] ok', ['provider' => $provider, 'q' => $q, 'chars' => mb_strlen($text), 'workspace_id' => $workspaceId]);
            return $text;
        } catch (\Throwable $e) {
            Log::warning('[WEB-SEARCH] ' . $e->getMessage(), ['provider' => $provider]);
            return '';
        }
    }

    /** Appended to a chat agent's system prompt when web search is on. */
    public function instruction(): string
    {
        return "--- Web search ---\n"
            . "You can look things up on the internet. If answering the customer's latest message needs current or outside information "
            . "you don't have (news, prices or rates, weather, public facts, other companies, events, schedules, how-to details) and it is NOT "
            . "covered by the business information above, reply with ONLY this single line and nothing else:\n"
            . self::MARKER . " <short search query>\n"
            . "Do not search for greetings, small talk, or questions about this business that the information above already answers.\n"
            . "--- End web search ---";
    }

    /**
     * If $reply is a search request, run it and return the follow-up answer
     * produced by $reask(string $resultsBlock). Otherwise returns $reply
     * unchanged. The marker line is never let through to the customer.
     */
    public function resolve(?string $reply, callable $reask, int $workspaceId = 0): ?string
    {
        $query = $this->extractQuery($reply);
        if ($query === null) return $reply;

        $results = $this->search($query, 5, $workspaceId);
        $block = $results !== ''
            ? "--- Web search results for \"{$query}\" ---\n{$results}\n--- End web search results ---\n\n"
              . "Answer the customer using these results where relevant. Keep it short and natural for chat. "
              . "Do not invent details that are not in the results. Do NOT output " . self::MARKER . " again."
            : "A web search for \"{$query}\" returned nothing. Answer from what you know, or say you couldn't find that information. "
              . "Do NOT output " . self::MARKER . " again.";

        $second = $reask($block);
        if ($this->extractQuery($second) !== null || $second === null) {
            // Model asked again or failed: strip the marker; never send it.
            $clean = trim((string) preg_replace('/^\s*' . preg_quote(self::MARKER, '/') . '.*$/mi', '', (string) $second));
            return $clean !== '' ? $clean : null;
        }
        return $second;
    }

    /**
     * Flow AI nodes: run $ask($systemPrompt) and, when the node's attached
     * training assistant has "Search the web" on, let it search first.
     * Without that assistant setting this is exactly one plain $ask call.
     */
    public function answer($assistant, string $system, callable $ask, int $workspaceId = 0): ?string
    {
        if (!$assistant || empty($assistant->web_search) || !$this->configured()) {
            return $ask($system);
        }
        $system .= "\n\n" . $this->instruction();
        return $this->resolve($ask($system), fn (string $results) => $ask($system . "\n\n" . $results), $workspaceId);
    }

    /** The query from a "SEARCH_WEB: ..." reply, or null when it isn't one. */
    public function extractQuery(?string $reply): ?string
    {
        if ($reply === null) return null;
        if (!preg_match('/^\s*[`*_"]*' . preg_quote(self::MARKER, '/') . '\s*(.+)$/mi', $reply, $m)) return null;
        // Only treat it as a request when the marker is the first thing said.
        $before = trim(mb_substr($reply, 0, (int) mb_strpos($reply, self::MARKER)), " \t\n\r`*_\"");
        if ($before !== '') return null;
        $q = trim($m[1], " \t`*_\"'");
        return $q !== '' ? mb_substr($q, 0, 200) : null;
    }

    private function line(string $title, string $body, string $url): string
    {
        $body = trim(preg_replace('/\s+/', ' ', $body));
        return '- ' . trim($title) . ($body !== '' ? ': ' . mb_substr($body, 0, 500) : '') . ($url !== '' ? ' (' . $url . ')' : '');
    }

    private function underDailyCap(int $workspaceId): bool
    {
        $cap = (int) SystemSetting::get('web_search_daily_cap', 300);
        if ($cap <= 0) return true;
        $k = 'websearch:cap:' . $workspaceId . ':' . now()->format('Ymd');
        Cache::add($k, 0, now()->addDay());
        return Cache::increment($k) <= $cap;
    }
}
