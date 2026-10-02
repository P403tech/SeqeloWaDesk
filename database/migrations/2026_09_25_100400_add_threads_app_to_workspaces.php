<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-workspace BYO Threads app (client's own Threads/Meta app id + secret), so
 * a workspace can connect Threads through its OWN app while the platform app is
 * in review — mirrors ownMetaApp / ownIgLoginApp. Secret is encrypted via the
 * Workspace model cast.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workspaces')) {
            return;
        }

        Schema::table('workspaces', function (Blueprint $table) {
            if (! Schema::hasColumn('workspaces', 'threads_app_id')) {
                $table->string('threads_app_id', 64)->nullable();
            }
            if (! Schema::hasColumn('workspaces', 'threads_app_secret')) {
                $table->text('threads_app_secret')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            foreach (['threads_app_id', 'threads_app_secret'] as $col) {
                if (Schema::hasColumn('workspaces', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
