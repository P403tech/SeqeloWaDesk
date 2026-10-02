<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "New chat" support for the help widget. When a user starts a fresh chat we
 * mark their previous turns hidden (hidden_at = now) instead of deleting them,
 * so the widget stops reloading them but the admin "what are users asking"
 * analytics keep every row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('support_bot_logs') && ! Schema::hasColumn('support_bot_logs', 'hidden_at')) {
            Schema::table('support_bot_logs', fn (Blueprint $t) => $t->timestamp('hidden_at')->nullable()->after('rating'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('support_bot_logs') && Schema::hasColumn('support_bot_logs', 'hidden_at')) {
            Schema::table('support_bot_logs', fn (Blueprint $t) => $t->dropColumn('hidden_at'));
        }
    }
};
