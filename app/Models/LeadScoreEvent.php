<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail of every lead-score change — powers the SDR dashboard's
 * "why did this lead score N?" explanation. Append-only (created_at only).
 */
class LeadScoreEvent extends Model
{
    public const UPDATED_AT = null;   // append-only log

    protected $fillable = [
        'workspace_id', 'contact_id', 'rule_id', 'signal', 'points', 'score_after', 'context',
    ];

    protected $casts = [
        'points'      => 'integer',
        'score_after' => 'integer',
        'context'     => 'array',
        'created_at'  => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
