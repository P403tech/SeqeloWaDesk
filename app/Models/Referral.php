<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per referred-user-attribution. We attribute exactly once,
 * at signup, and never again (the unique key on `referred_user_id`
 * enforces this at the DB level — race-safe). If a referee later
 * changes their plan / makes a purchase, we do NOT re-credit on this
 * row; instead we'd add a separate ledger entry pointing back to
 * this referral.
 */
class Referral extends Model
{
    public $timestamps = false; // only created_at

    // Pipeline. `pending` = friend joined, not yet paid. `paid` = friend made
    // their first paid top-up and both sides were rewarded. `expired` = the
    // qualifying window closed with no purchase. `void` = reversed (refund/fraud).
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID    = 'paid';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_VOID    = 'void';

    protected $fillable = [
        'referrer_user_id', 'referred_user_id', 'code_used', 'status',
        'credits_awarded', 'reward_minor', 'referee_reward_minor',
        'award_transaction_id', 'referee_award_transaction_id',
        'created_at', 'qualified_at',
    ];

    protected $casts = [
        'credits_awarded'      => 'integer',
        'reward_minor'         => 'integer',
        'referee_reward_minor' => 'integer',
        'created_at'           => 'datetime',
        'qualified_at'         => 'datetime',
    ];

    /** True once the referee has paid and both sides were rewarded. */
    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID || $this->award_transaction_id !== null;
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function awardTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'award_transaction_id');
    }

    public function scopeForReferrer(Builder $q, int $userId): Builder
    {
        return $q->where('referrer_user_id', $userId);
    }
}
