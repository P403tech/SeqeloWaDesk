<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat Lock for the team inbox. A locked conversation stays in the list but its
 * preview is hidden and its messages are withheld until the operator enters the
 * workspace inbox PIN (verified per browser session). Meta has NO chat-lock API
 * (it's a consumer-app feature), so this is enforced entirely on our side.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('conversations') && ! Schema::hasColumn('conversations', 'locked_at')) {
            Schema::table('conversations', function (Blueprint $t) {
                $t->timestamp('locked_at')->nullable()->after('muted_at')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('conversations') && Schema::hasColumn('conversations', 'locked_at')) {
            Schema::table('conversations', fn (Blueprint $t) => $t->dropColumn('locked_at'));
        }
    }
};
