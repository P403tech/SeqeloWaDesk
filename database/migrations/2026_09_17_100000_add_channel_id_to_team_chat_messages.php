<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Team chat messages were never stored against a channel.
 *
 * team_chat_messages was created before channels existed, and the column was
 * never added when they arrived. TeamChatController::store() passes
 * 'channel_id' => $ch->id to create(), and it went nowhere: the key wasn't in
 * $fillable (silently dropped by mass assignment) and the column wasn't there
 * either.
 *
 * Every read is `where('channel_id', …)`, so every message was invisible the
 * moment it was written — to the recipient AND the sender. The sender only
 * appeared to see it because the browser renders the message optimistically
 * before reloading from the server.
 *
 * Backfill points existing orphans at that workspace's #general so nothing
 * written before this is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('team_chat_messages')) {
            return;
        }

        if (! Schema::hasColumn('team_chat_messages', 'channel_id')) {
            Schema::table('team_chat_messages', function (Blueprint $table) {
                $table->unsignedBigInteger('channel_id')->nullable()->after('workspace_id');
                // The read path's hot query: newest messages in one channel.
                $table->index(['channel_id', 'id'], 'tcm_channel_id_idx');
            });
        }

        // Rescue anything already written without a channel: file it under the
        // workspace's #general, which is where the UI was posting from.
        if (Schema::hasTable('team_chat_channels')) {
            try {
                DB::table('team_chat_messages')
                    ->whereNull('channel_id')
                    ->update([
                        'channel_id' => DB::raw(
                            '(SELECT id FROM team_chat_channels c'
                            . ' WHERE c.workspace_id = team_chat_messages.workspace_id'
                            . " AND c.slug = 'general' LIMIT 1)"
                        ),
                    ]);
            } catch (\Throwable $e) {
                // A workspace with no #general yet simply keeps NULL; the
                // channel is auto-seeded on next visit.
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('team_chat_messages')) {
            return;
        }

        Schema::table('team_chat_messages', function (Blueprint $table) {
            try { $table->dropIndex('tcm_channel_id_idx'); } catch (\Throwable $e) {}
            if (Schema::hasColumn('team_chat_messages', 'channel_id')) {
                $table->dropColumn('channel_id');
            }
        });
    }
};
