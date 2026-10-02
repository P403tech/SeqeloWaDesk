<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One (rule × recipient) follow-up: the idempotency guard for an immediate fire,
 * and the durable due-row for a delayed one. `due_at` is the whole point for
 * delayed rules — a pending action is a row with a timestamp, not a RAM timer,
 * so a restart delays it rather than losing it (mirrors DripSubscriber).
 */
class CampaignFollowupRun extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_FIRED   = 'fired';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED  = 'failed';

    protected $fillable = [
        'campaign_followup_id', 'wp_campaign_contact_id', 'contact_id',
        'due_at', 'status', 'fired_at', 'result_json',
    ];

    protected $casts = [
        'due_at'      => 'datetime',
        'fired_at'    => 'datetime',
        'result_json' => 'array',
    ];

    public function followup(): BelongsTo
    {
        return $this->belongsTo(CampaignFollowup::class, 'campaign_followup_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(WpCampaignContact::class, 'wp_campaign_contact_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** The sweeper's only query: delayed runs whose time has come. */
    public function scopeDue($q)
    {
        return $q->where('status', self::STATUS_PENDING)
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now());
    }
}
