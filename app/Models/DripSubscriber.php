<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One contact's position in one drip campaign.
 *
 * `next_send_at` is the whole point of this table. A pending follow-up is a
 * durable row with a due timestamp — not a timer held in a process — so a
 * deploy or crash delays it rather than losing it.
 */
class DripSubscriber extends Model
{
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_STOPPED   = 'stopped';
    public const STATUS_FAILED    = 'failed';

    /** How many times one step is retried before it is given up on. */
    public const MAX_ATTEMPTS = 4;

    protected $fillable = [
        'drip_campaign_id', 'contact_id', 'workspace_id',
        'status', 'current_step',
        'next_send_at', 'last_sent_at',
        'stopped_reason', 'sent_count', 'fail_count',
        'attempts', 'last_error',
    ];

    protected $casts = [
        'current_step'  => 'integer',
        'sent_count'    => 'integer',
        'fail_count'    => 'integer',
        'attempts'      => 'integer',
        'next_send_at'  => 'datetime',
        'last_sent_at'  => 'datetime',
    ];

    /** Backoff before retrying the current step: 1m, 5m, 30m, 2h. */
    public function retryDelaySeconds(): int
    {
        return [0 => 60, 1 => 300, 2 => 1800, 3 => 7200][$this->attempts] ?? 7200;
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(DripCampaign::class, 'drip_campaign_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** Everything due to send right now. The drain's only query. */
    public function scopeDue($q)
    {
        return $q->where('status', self::STATUS_ACTIVE)
            ->whereNotNull('next_send_at')
            ->where('next_send_at', '<=', now());
    }

    public function stop(string $reason): void
    {
        $this->update([
            'status'         => self::STATUS_STOPPED,
            'stopped_reason' => mb_substr($reason, 0, 191),
            'next_send_at'   => null,
        ]);
    }
}
