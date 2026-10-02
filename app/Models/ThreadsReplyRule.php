<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A Threads reply auto-responder rule. Mirrors FacebookCommentRule: a keyword
 * (comma list) matched against a reply body, then a public reply and/or hide.
 */
class ThreadsReplyRule extends Model
{
    protected $fillable = [
        'workspace_id', 'threads_account_id', 'post_media_id', 'name',
        'keyword', 'keyword_mode', 'reply_text', 'hide', 'is_active', 'matched_count',
        'use_ai', 'ai_prompt',
    ];

    protected $casts = [
        'hide'          => 'boolean',
        'is_active'     => 'boolean',
        'matched_count' => 'int',
        'use_ai'        => 'boolean',
    ];

    public function scopeForWorkspace($q, int $workspaceId)
    {
        return $q->where('workspace_id', $workspaceId);
    }

    /**
     * Does a reply body match this rule's keyword under its mode?
     * contains (default) | exact | any (catch-all). Case-insensitive; a
     * comma-separated keyword list matches on ANY entry.
     */
    public function matches(string $text): bool
    {
        $mode = strtolower((string) ($this->keyword_mode ?: 'contains'));
        if ($mode === 'any') return true;

        $kw = strtolower(trim((string) ($this->keyword ?? '')));
        $body = strtolower(trim($text));
        if ($kw === '') return false;

        $terms = array_filter(array_map('trim', explode(',', $kw)));
        foreach ($terms as $term) {
            if ($term === '') continue;
            if ($mode === 'exact' ? ($body === $term) : str_contains($body, $term)) {
                return true;
            }
        }
        return false;
    }
}
