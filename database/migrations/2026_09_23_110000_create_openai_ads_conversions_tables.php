<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OpenAI Ads conversion setup — local mirror of pixels + event settings + a
 * lightweight record of created Conversions API keys (the secret itself is NEVER
 * stored; shown once on creation, only a masked hint kept). Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('openai_ads_pixels')) {
            Schema::create('openai_ads_pixels', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->string('remote_id')->nullable()->index();  // source id clidsrc_...
                $table->string('pixel_id')->nullable();            // used for JS pixel init
                $table->string('name');
                $table->string('client_type')->default('web');
                $table->json('meta_json')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('openai_ads_event_settings')) {
            Schema::create('openai_ads_event_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->string('remote_id')->nullable()->index();  // ces_...
                $table->string('name');
                $table->string('event_type');
                $table->string('custom_event_name')->nullable();
                $table->unsignedInteger('attribution_window_days')->default(30);
                $table->json('source_ids')->nullable();
                $table->unsignedBigInteger('pixel_local_id')->nullable();
                $table->json('meta_json')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('openai_ads_api_keys')) {
            Schema::create('openai_ads_api_keys', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->string('name');
                $table->string('masked')->nullable();  // e.g. "…a1b2" — the secret itself is NOT stored
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('openai_ads_api_keys');
        Schema::dropIfExists('openai_ads_event_settings');
        Schema::dropIfExists('openai_ads_pixels');
    }
};
