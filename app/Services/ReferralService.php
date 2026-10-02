<?php

namespace App\Services;

use App\Models\Referral;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Referral attribution + payout. Called from AuthController on
 * register, and only there — there's no admin "re-attribute" path
 * (the unique constraint on `referrals.referred_user_id` enforces
 * one-and-done).
 *
 * Payout amount is read from `system_settings.referral_signup_credits`,
 * which the admin tunes from /admin/settings. Defaults to 100.
 */
class ReferralService
{
    public function __construct(private WalletService $wallet) {}

    /**
     * Look up the referrer user from a code captured at signup-time.
     * Self-referrals (code belongs to the same user) are ignored —
     * defensive against a clever user pasting their own link into
     * incognito.
     */
    public function findReferrer(?string $code, ?int $excludeUserId = null): ?User
    {
        $code = trim((string) $code);
        if ($code === '') return null;
        $code = strtoupper($code);
        return User::query()
            ->where('referral_code', $code)
            ->when($excludeUserId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->first();
    }

    /**
     * Attribute a referee to a referrer + award the configured
     * signup credits in one transaction. Idempotent — a second call
     * for the same referee no-ops thanks to the unique constraint
     * on `referrals.referred_user_id`.
     *
     * Returns the Referral row (existing or new).
     */
    public function attribute(User $referrer, User $referee, string $codeUsed): ?Referral
    {
        if ($referrer->id === $referee->id) return null;

        $existing = Referral::where('referred_user_id', $referee->id)->first();
        if ($existing) return $existing;

        return DB::transaction(function () use ($referrer, $referee, $codeUsed) {
            // Persist the referee → referrer link on `users` first so any
            // subsequent reads (admin views, audit) reflect the graph.
            $referee->forceFill(['referred_by_user_id' => $referrer->id])->save();

            // ATTRIBUTION ONLY — no payout at signup. Both sides are rewarded on
            // the referee's FIRST PAID top-up (see rewardOnFirstPayment), so a user
            // can't farm free money by mass-creating throwaway accounts.
            // status=pending / award_transaction_id=NULL = "joined, not yet paid".
            return Referral::create([
                'referrer_user_id'     => $referrer->id,
                'referred_user_id'     => $referee->id,
                'code_used'            => $codeUsed,
                'status'               => Referral::STATUS_PENDING,
                'credits_awarded'      => 0,
                'award_transaction_id' => null,
                'created_at'           => now(),
            ]);
        });
    }

    /** The referrer's reward (money, minor units). New key, legacy fallback. */
    public static function referrerRewardMinor(): int
    {
        $v = SystemSetting::get('referral_referrer_reward_minor', null);
        if ($v === null || $v === '') {
            // Back-compat: the old single "Signup reward" key held the referrer's
            // money reward under a misleading name.
            $v = SystemSetting::get('referral_signup_credits', 100);
        }
        return max(0, (int) $v);
    }

    /** The referee's welcome bonus (money, minor units). 0 = one-sided. */
    public static function refereeRewardMinor(): int
    {
        return max(0, (int) SystemSetting::get('referral_referee_reward_minor', 0));
    }

    public static function enabled(): bool
    {
        // Default ON so the existing behaviour is unchanged until an admin opts out.
        return (bool) SystemSetting::get('referral_enabled', true);
    }

    /**
     * TWO-SIDED reward on the referee's FIRST paid top-up: the referrer earns
     * their referral reward AND the referee earns a welcome bonus — both as wallet
     * money. Idempotent + safe to call on every successful checkout: only fires
     * when a pending referral exists for this referee, then stamps the txn ids +
     * status=paid so a renewal / second order never double-pays.
     */
    public function rewardOnFirstPayment(User $referee): void
    {
        if (! self::enabled()) return;

        $referral = Referral::where('referred_user_id', $referee->id)
            ->whereNull('award_transaction_id')
            ->first();
        if (! $referral) return;

        $referrer = User::find($referral->referrer_user_id);
        if (! $referrer || $referrer->id === $referee->id) return;

        // Rewards are MONEY (minor units); convert to wallet credits through the
        // SAME helper top-ups use so money and credits can never disagree (this is
        // the conflation that once caused a 10,000× overpay).
        $referrerMinor  = self::referrerRewardMinor();
        $refereeMinor   = self::refereeRewardMinor();
        $referrerCredit = $this->wallet->creditsForMinor($referrerMinor);
        $refereeCredit  = $this->wallet->creditsForMinor($refereeMinor);

        if ($referrerCredit <= 0 && $refereeCredit <= 0) {
            \Illuminate\Support\Facades\Log::warning('[REFERRAL] both rewards round to zero — nothing awarded', [
                'referrer_id' => $referrer->id, 'referee_id' => $referee->id,
                'referrer_minor' => $referrerMinor, 'referee_minor' => $refereeMinor,
                'hint' => 'Raise the referral rewards in /admin/settings/wallet-rules.',
            ]);
            return;
        }

        DB::transaction(function () use ($referrer, $referee, $referral, $referrerCredit, $refereeCredit, $referrerMinor, $refereeMinor) {
            $awardTx = null;
            if ($referrerCredit > 0) {
                $awardTx = $this->wallet->creditAccount(
                    $referrer,
                    $referrerCredit,
                    'referral.paid',
                    $referee,
                    "Referral reward — {$referee->email} made their first paid top-up with your link",
                    ['referee_id' => $referee->id, 'code_used' => $referral->code_used]
                );
            }

            // REFEREE welcome bonus — the two-sided half. Credited to the friend
            // who was invited, on their first paid top-up.
            $refereeTx = null;
            if ($refereeCredit > 0) {
                $refereeTx = $this->wallet->creditAccount(
                    $referee,
                    $refereeCredit,
                    'referral.welcome',
                    $referrer,
                    'Welcome bonus — thanks for joining with a referral link',
                    ['referrer_id' => $referrer->id, 'code_used' => $referral->code_used]
                );
            }

            $referral->forceFill([
                'status'                       => Referral::STATUS_PAID,
                'credits_awarded'              => $referrerCredit,   // referrer credits landed
                'reward_minor'                 => $referrerMinor,    // referrer money (minor)
                'referee_reward_minor'         => $refereeMinor,     // referee money (minor)
                'award_transaction_id'         => $awardTx?->id,
                'referee_award_transaction_id' => $refereeTx?->id,
                'qualified_at'                 => now(),
            ])->save();
        });
    }

    /** Expire pending referrals whose qualifying window has closed (no cron). */
    public function expireStale(): int
    {
        $days = max(1, (int) SystemSetting::get('referral_window_days', 30));

        return Referral::where('status', Referral::STATUS_PENDING)
            ->whereNull('award_transaction_id')
            ->where('created_at', '<', now()->subDays($days))
            ->update(['status' => Referral::STATUS_EXPIRED]);
    }
}
