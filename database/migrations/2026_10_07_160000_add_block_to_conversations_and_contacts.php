<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Block" for the team inbox. Blocking a contact hides every conversation with
 * them from the inbox (and everywhere it lists) and stops new inbound from
 * resurfacing a thread. Enforced locally for all channels; WABA additionally
 * calls Meta's /{phone_number_id}/block_users so Meta stops delivering.
 *
 *   conversations.blocked_at — this thread is blocked (excluded from listings).
 *   contacts.is_blocked      — the person is blocked (any new thread from them
 *                              is created already-blocked; drives the Meta call).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('conversations') && ! Schema::hasColumn('conversations', 'blocked_at')) {
            Schema::table('conversations', function (Blueprint $t) {
                $t->timestamp('blocked_at')->nullable()->after('muted_at')->index();
            });
        }
        if (Schema::hasTable('contacts') && ! Schema::hasColumn('contacts', 'is_blocked')) {
            Schema::table('contacts', function (Blueprint $t) {
                $t->boolean('is_blocked')->default(false)->index();
                $t->timestamp('blocked_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('conversations') && Schema::hasColumn('conversations', 'blocked_at')) {
            Schema::table('conversations', fn (Blueprint $t) => $t->dropColumn('blocked_at'));
        }
        if (Schema::hasTable('contacts') && Schema::hasColumn('contacts', 'is_blocked')) {
            Schema::table('contacts', fn (Blueprint $t) => $t->dropColumn(['is_blocked', 'blocked_at']));
        }
    }
};
