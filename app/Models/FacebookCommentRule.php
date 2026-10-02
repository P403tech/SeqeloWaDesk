<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A Facebook comment auto-reply rule. See the migration — the Facebook
 * counterpart of instagram_comment_rules.
 */
class FacebookCommentRule extends Model
{
    protected $fillable = [
        'workspace_id', 'fb_page_id', 'post_id', 'name',
        'keyword', 'keyword_mode', 'public_reply', 'dm_text', 'dm_flow_id',
        'is_active', 'matched_count',
    ];

    protected $casts = [
        'is_active'     => 'boolean',
        'matched_count' => 'int',
        'dm_flow_id'    => 'int',
    ];

    /**
     * Does a comment body match this rule's keyword under its mode?
     * contains (default) | exact | any (catch-all). Case-insensitive.
     */
    public function matches(string $text): bool
    {
        $mode = strtolower((string) ($this->keyword_mode ?: 'contains'));
        if ($mode === 'any') return true;

        $kw = strtolower(trim((string) ($this->keyword ?? '')));
        $body = strtolower(trim($text));
        if ($kw === '') return $mode === 'any';

        // A comma-separated keyword list matches on ANY entry.
        $terms = array_filter(array_map('trim', explode(',', $kw)));
        if (empty($terms)) $terms = [$kw];

        foreach ($terms as $term) {
            if ($term === '') continue;
            if ($mode === 'exact' ? ($body === $term) : str_contains($body, $term)) {
                return true;
            }
        }
        return false;
    }
}
