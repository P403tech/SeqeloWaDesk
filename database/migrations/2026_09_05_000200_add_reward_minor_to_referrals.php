<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Separate the two numbers a referral payout actually has.
 *
 * `referrals.credits_awarded` was doing double duty. The affiliate "Earned"
 * card divided it by 100 to print MONEY on one line, and by credits-per-message
 * to print MESSAGES on the very next line — the same figure read as two
 * different units. Whichever unit it truly held, one of those lines was wrong.
 *
 * From here:
 *   reward_minor    — the money awarded, in minor units
 *   credits_awarded — the credits that actually landed in the wallet
 *
 * Backfill sets reward_minor = credits_awarded for existing rows: before this
 * ships, that column held the money-minor figure (the payout credited it raw),
 * so the money line keeps reading exactly as it always has. Historical rows
 * therefore stay truthful about money, and truthful about credits too — those
 * referrers really did receive that many credits under the old behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('referrals')) {
            return;
        }
        if (! Schema::hasColumn('referrals', 'reward_minor')) {
            Schema::table('referrals', function (Blueprint $t) {
                $t->unsignedBigInteger('reward_minor')->nullable()->after('credits_awarded');
            });
            // Existing rows: credits_awarded held money-minor at award time.
            DB::table('referrals')->whereNull('reward_minor')
                ->update(['reward_minor' => DB::raw('credits_awarded')]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('referrals') && Schema::hasColumn('referrals', 'reward_minor')) {
            Schema::table('referrals', function (Blueprint $t) {
                $t->dropColumn('reward_minor');
            });
        }
    }
};
