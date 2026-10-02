<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dedupe log for processed Threads replies — one row per reply we've already
 * seen, so the poller never re-replies to or re-hides the same reply. Unique on
 * (threads_account_id, reply_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('threads_reply_log')) {
            return;
        }

        Schema::create('threads_reply_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('threads_account_id')->index();
            $table->string('reply_id', 64);
            $table->string('post_media_id', 64)->nullable();
            $table->string('from_username', 191)->nullable();
            $table->text('text')->nullable();
            $table->unsignedBigInteger('matched_rule_id')->nullable();
            $table->string('action', 24)->default('none');   // none | replied | hidden | replied_hidden
            $table->string('replied_media_id', 64)->nullable();
            $table->timestamps();

            $table->unique(['threads_account_id', 'reply_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('threads_reply_log');
    }
};
