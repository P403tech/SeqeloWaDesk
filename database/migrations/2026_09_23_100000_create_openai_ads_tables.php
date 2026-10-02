<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local mirror of OpenAI (ChatGPT) Ads objects. Each row shadows a remote
 * object (campaign / ad group / ad) so the UI is fast and survives API hiccups
 * — same approach as MetaCampaign. `remote_id` is the OpenAI id (cmpn_/adgrp_/ad_);
 * money is stored in micros (integer). All idempotent (hasTable guards) per the
 * project's resilient-migrations rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('openai_ads_campaigns')) {
            Schema::create('openai_ads_campaigns', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('remote_id')->nullable()->index();     // cmpn_...
                $table->string('name');
                $table->string('status')->default('paused');          // active | paused | archived
                $table->string('bidding_type')->nullable();           // impressions | clicks | conversions
                $table->unsignedBigInteger('budget_micros')->default(0);
                $table->json('conversion_event_setting_ids')->nullable();
                $table->timestamp('last_synced_at')->nullable();
                $table->json('meta_json')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('openai_ads_ad_groups')) {
            Schema::create('openai_ads_ad_groups', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedBigInteger('campaign_id')->index();   // local FK
                $table->string('remote_id')->nullable()->index();     // adgrp_...
                $table->string('name');
                $table->string('status')->default('paused');
                $table->string('billing_event_type')->nullable();     // impression | click
                $table->unsignedBigInteger('max_bid_micros')->default(0);
                $table->json('meta_json')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('openai_ads_ads')) {
            Schema::create('openai_ads_ads', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedBigInteger('ad_group_id')->index();   // local FK
                $table->string('remote_id')->nullable()->index();     // ad_...
                $table->string('name');
                $table->string('status')->default('paused');
                $table->string('review_status')->nullable();          // in_review | rejected | approved
                $table->string('creative_type')->nullable();          // chat_card | product_ad_template
                $table->string('title')->nullable();
                $table->string('body', 500)->nullable();
                $table->string('price')->nullable();
                $table->text('target_url')->nullable();
                $table->string('file_id')->nullable();
                $table->text('preview_url')->nullable();
                $table->json('meta_json')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('openai_ads_ads');
        Schema::dropIfExists('openai_ads_ad_groups');
        Schema::dropIfExists('openai_ads_campaigns');
    }
};
