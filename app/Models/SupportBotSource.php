<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One knowledge source for the platform Client Support Bot — an uploaded file,
 * a fetched URL, or a raw text snippet. The extracted plain text is kept in
 * `content`; the searchable chunks derived from it live in support_bot_chunks.
 */
class SupportBotSource extends Model
{
    use SoftDeletes;

    protected $table = 'support_bot_sources';

    protected $fillable = [
        'kind', 'label', 'url', 'source_path', 'content',
        'status', 'tokens_estimate', 'error', 'created_by',
    ];

    public function chunks(): HasMany
    {
        return $this->hasMany(SupportBotChunk::class, 'source_id');
    }
}
