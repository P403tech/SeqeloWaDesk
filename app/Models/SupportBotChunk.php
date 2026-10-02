<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One searchable passage of a support source. Retrieval scores these (FULLTEXT
 * on MySQL, LIKE fallback elsewhere) and returns the best-matching chunks.
 */
class SupportBotChunk extends Model
{
    protected $table = 'support_bot_chunks';

    protected $fillable = [
        'source_id', 'heading', 'content', 'position',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(SupportBotSource::class, 'source_id');
    }
}
