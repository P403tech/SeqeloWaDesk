<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facebook comment auto-reply rules — the Facebook counterpart of
 * instagram_comment_rules. A new Page comment whose text matches a rule's
 * keyword can trigger, in order: a PUBLIC reply under the comment
 * (FacebookPageClient::replyComment), a private reply / DM to the commenter
 * (FacebookPageClient::privateReply), and optionally a flow (dm_flow_id).
 * Fired by FacebookIngestService::feedComment (FB comments were previously
 * inbox-only — "moderation, not conversational auto-reply").
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('facebook_comment_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('fb_page_id')->index();   // facebook_pages.id
            $table->string('post_id', 64)->nullable();           // null = any post
            $table->string('name', 191)->nullable();
            $table->string('keyword', 191)->nullable();
            $table->string('keyword_mode', 16)->default('contains'); // contains | exact | any
            $table->text('public_reply')->nullable();
            $table->text('dm_text')->nullable();
            $table->unsignedBigInteger('dm_flow_id')->nullable();    // optional flow to run
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('matched_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_comment_rules');
    }
};
