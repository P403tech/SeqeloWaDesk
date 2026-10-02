<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-answer rating for the Client Support Bot. A user can thumbs-up/down each
 * bot answer in the help widget; the admin "docs thin" view can then surface
 * which answers were unhelpful. NULL = unrated, 1 = helpful, -1 = not helpful.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('support_bot_logs') && ! Schema::hasColumn('support_bot_logs', 'rating')) {
            Schema::table('support_bot_logs', fn (Blueprint $t) => $t->tinyInteger('rating')->nullable()->after('matched'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('support_bot_logs') && Schema::hasColumn('support_bot_logs', 'rating')) {
            Schema::table('support_bot_logs', fn (Blueprint $t) => $t->dropColumn('rating'));
        }
    }
};
