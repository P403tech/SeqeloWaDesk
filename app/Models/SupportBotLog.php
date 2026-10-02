<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One user question answered (or not) by the Client Support Bot, with the tier
 * that answered it. Powers the admin "what are users asking / where are the
 * docs thin" view.
 */
class SupportBotLog extends Model
{
    protected $table = 'support_bot_logs';

    protected $fillable = [
        'user_id', 'question', 'answer', 'matched', 'rating', 'hidden_at',
        'score', 'engine', 'source_id', 'session_id', 'ip',
    ];

    protected $casts = [
        'matched'   => 'boolean',
        'score'     => 'float',
        'rating'    => 'integer',
        'hidden_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
