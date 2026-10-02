<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Threads reply auto-responder rules — the Threads counterpart of
 * facebook_comment_rules. When a reply to one of the account's posts matches a
 * rule's keyword, we post a public reply and/or hide it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('threads_reply_rules')) {
            return;
        }

        Schema::create('threads_reply_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('threads_account_id')->nullable()->index(); // null = any connected account
            $table->string('post_media_id', 64)->nullable();                        // null = any post
            $table->string('name', 120)->nullable();
            $table->string('keyword', 500)->nullable();
            $table->string('keyword_mode', 16)->default('contains');                // contains | exact | any
            $table->text('reply_text')->nullable();                                 // public reply body
            $table->boolean('hide')->default(false);                                // hide the matching reply
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('matched_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('threads_reply_rules');
    }
};
