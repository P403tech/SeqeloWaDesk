<?php

namespace App\Services\Ai;

use App\Models\AiChatAssistant;
use App\Models\AiTrainingSource;

/**
 * Trained Live URL pages for the smart agent: answer from the page text
 * and share the real link. Same Knowledge → Live URL feature operators
 * already use — surfaced as shareable website content.
 */
class AgentWebsiteContext
{
    public static function promptBlock(?AiChatAssistant $assistant): string
    {
        if (! $assistant) {
            return '';
        }

        try {
            $rows = AiTrainingSource::query()
                ->where('workspace_id', (int) $assistant->workspace_id)
                ->where(function ($q) use ($assistant) {
                    $q->whereNull('assistant_id')->orWhere('assistant_id', $assistant->id);
                })
                ->where('kind', 'url')
                ->where('status', 'ready')
                ->whereNotNull('url')
                ->orderBy('id')
                ->limit(12)
                ->get();
        } catch (\Throwable $e) {
            return '';
        }

        if ($rows->isEmpty()) {
            return '';
        }

        $lines = [];
        $lines[] = 'Website pages from Knowledge (Live URL). You may cite these and share their links. Never invent a URL that is not on this list.';
        $lines[] = 'If the customer asks for a page, brochure, catalog, blog, policy, or “send the link”, give a short summary from the matching page AND paste that URL.';
        $lines[] = 'If they ask you to write a caption, post, or story, write it only from these pages plus Business information, then include the matching URL so they can share it.';

        foreach ($rows as $r) {
            $url = trim((string) $r->url);
            $label = trim((string) $r->label) ?: $url;
            $excerpt = trim((string) ($r->content ?? ''));
            $excerpt = preg_replace('/\s+/', ' ', $excerpt) ?? $excerpt;
            $excerpt = mb_substr($excerpt, 0, 280);
            $lines[] = '- '.$label.' · '.$url.($excerpt !== '' ? ' — '.$excerpt : '');
        }

        return implode("\n", $lines);
    }
}
