<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Threads plan gates on the packages table — one boolean per feature + one
 * integer per limit, mirroring how the other channels (facebook/telegram/…)
 * add theirs. Idempotent (guarded per column) so re-running is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('packages')) {
            return;
        }

        Schema::table('packages', function (Blueprint $table) {
            if (! Schema::hasColumn('packages', 'access_threads')) {
                $table->boolean('access_threads')->nullable();
            }
            if (! Schema::hasColumn('packages', 'threads_posts')) {
                $table->boolean('threads_posts')->nullable();
            }
            if (! Schema::hasColumn('packages', 'threads_accounts')) {
                $table->integer('threads_accounts')->nullable();
            }
            if (! Schema::hasColumn('packages', 'threads_scheduled_posts')) {
                $table->integer('threads_scheduled_posts')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            foreach (['access_threads', 'threads_posts', 'threads_accounts', 'threads_scheduled_posts'] as $col) {
                if (Schema::hasColumn('packages', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
