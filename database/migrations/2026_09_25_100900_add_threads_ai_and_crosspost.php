<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Threads extras: AI-generated replies (on reply rules) + cross-share-to-Instagram
 * (on composed posts). Both idempotent/guarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('threads_reply_rules')) {
            Schema::table('threads_reply_rules', function (Blueprint $table) {
                if (! Schema::hasColumn('threads_reply_rules', 'use_ai')) {
                    $table->boolean('use_ai')->default(false);
                }
                if (! Schema::hasColumn('threads_reply_rules', 'ai_prompt')) {
                    $table->text('ai_prompt')->nullable();   // extra instruction for the AI reply
                }
            });
        }

        if (Schema::hasTable('threads_scheduled_posts')) {
            Schema::table('threads_scheduled_posts', function (Blueprint $table) {
                if (! Schema::hasColumn('threads_scheduled_posts', 'cross_to_ig')) {
                    $table->boolean('cross_to_ig')->default(false);  // also share to Instagram
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('threads_reply_rules')) {
            Schema::table('threads_reply_rules', function (Blueprint $table) {
                foreach (['use_ai', 'ai_prompt'] as $col) {
                    if (Schema::hasColumn('threads_reply_rules', $col)) $table->dropColumn($col);
                }
            });
        }
        if (Schema::hasTable('threads_scheduled_posts') && Schema::hasColumn('threads_scheduled_posts', 'cross_to_ig')) {
            Schema::table('threads_scheduled_posts', fn (Blueprint $table) => $table->dropColumn('cross_to_ig'));
        }
    }
};
