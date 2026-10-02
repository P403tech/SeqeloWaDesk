<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Refer & Earn" redesign — make the referrals table express the real pipeline
 * (pending → paid → expired) and carry the REFEREE-side welcome reward, so the
 * scheme becomes two-sided (both the referrer and the invited friend earn wallet
 * money on the friend's first paid top-up).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            if (! Schema::hasColumn('referrals', 'status')) {
                // pending | paid | expired | void
                $table->string('status', 16)->default('pending')->index()->after('code_used');
            }
            if (! Schema::hasColumn('referrals', 'referee_reward_minor')) {
                $table->unsignedBigInteger('referee_reward_minor')->nullable()->after('reward_minor');
            }
            if (! Schema::hasColumn('referrals', 'referee_award_transaction_id')) {
                $table->unsignedBigInteger('referee_award_transaction_id')->nullable()->after('award_transaction_id');
            }
            if (! Schema::hasColumn('referrals', 'qualified_at')) {
                $table->timestamp('qualified_at')->nullable()->after('created_at');
            }
        });

        // Backfill status from the existing payout marker so old rows read right.
        DB::table('referrals')->whereNotNull('award_transaction_id')->update(['status' => 'paid']);
        DB::table('referrals')->whereNull('award_transaction_id')->update(['status' => 'pending']);
    }

    public function down(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            foreach (['status', 'referee_reward_minor', 'referee_award_transaction_id', 'qualified_at'] as $col) {
                if (Schema::hasColumn('referrals', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
