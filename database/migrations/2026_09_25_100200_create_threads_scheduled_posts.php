<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Threads composer/scheduler posts. One row per composed or scheduled Threads
 * post. Fed by the unified Social Calendar and published by
 * ThreadsScheduledPostSweeper via the 2-step container → publish flow. Column
 * names mirror instagram_scheduled_posts so SocialPostAggregator normalises it
 * the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('threads_scheduled_posts')) {
            return;
        }

        Schema::create('threads_scheduled_posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('threads_account_id')->index();

            $table->string('media_type', 16)->default('text');    // text | image | video | carousel
            $table->text('text')->nullable();                     // the post body (Threads calls it "text")
            $table->string('image_url', 1024)->nullable();
            $table->string('video_url', 1024)->nullable();
            $table->json('carousel_urls')->nullable();            // [{type,url}, ...] for carousels
            $table->string('link_attachment', 1024)->nullable();  // TEXT posts only

            $table->timestamp('scheduled_at')->nullable()->index();
            $table->string('status', 16)->default('scheduled');   // scheduled | processing | published | failed
            $table->boolean('pending')->default(false);           // sweeper claim flag (in-flight)
            $table->string('creation_id', 64)->nullable();        // container id (in-flight)
            $table->string('media_id', 64)->nullable();           // published Threads media id
            $table->string('last_error', 500)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('threads_scheduled_posts');
    }
};
