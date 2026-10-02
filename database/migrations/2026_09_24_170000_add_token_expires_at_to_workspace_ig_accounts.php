<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `token_expires_at` to workspace_ig_accounts.
 *
 * The WorkspaceIgAccount model writes `token_expires_at` (mirrored from the
 * native account purely so /devices can warn before a token lapses), but the
 * original create migration never added the column — so a native FB-Login
 * Instagram connect failed at the mirror insert with:
 *   SQLSTATE[42S22] Unknown column 'token_expires_at' in 'field list'
 * which left the account off the dashboard (the dashboard reads this mirror).
 *
 * Idempotent — only adds columns that are missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workspace_ig_accounts')) return;

        Schema::table('workspace_ig_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('workspace_ig_accounts', 'token_expires_at')) {
                $table->timestamp('token_expires_at')->nullable()->after('status');
            }
            // Defensive: these belong to the model too; add if an older table is missing them.
            if (! Schema::hasColumn('workspace_ig_accounts', 'followers')) {
                $table->unsignedInteger('followers')->nullable();
            }
            if (! Schema::hasColumn('workspace_ig_accounts', 'synced_at')) {
                $table->timestamp('synced_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('workspace_ig_accounts')) return;

        Schema::table('workspace_ig_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('workspace_ig_accounts', 'token_expires_at')) {
                $table->dropColumn('token_expires_at');
            }
        });
    }
};
