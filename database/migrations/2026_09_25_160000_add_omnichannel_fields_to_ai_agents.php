<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One trained assistant can drive the inbox bot on WhatsApp + Facebook,
 * optionally using Shopify as a tool. Instagram/TikTok flags reserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_chat_assistants')) {
            Schema::table('ai_chat_assistants', function (Blueprint $table) {
                if (! Schema::hasColumn('ai_chat_assistants', 'business_brief')) {
                    $table->text('business_brief')->nullable();
                }
                if (! Schema::hasColumn('ai_chat_assistants', 'channel_whatsapp')) {
                    $table->boolean('channel_whatsapp')->default(true);
                }
                if (! Schema::hasColumn('ai_chat_assistants', 'channel_facebook')) {
                    $table->boolean('channel_facebook')->default(false);
                }
                if (! Schema::hasColumn('ai_chat_assistants', 'channel_instagram')) {
                    $table->boolean('channel_instagram')->default(false);
                }
                if (! Schema::hasColumn('ai_chat_assistants', 'channel_tiktok')) {
                    $table->boolean('channel_tiktok')->default(false);
                }
                if (! Schema::hasColumn('ai_chat_assistants', 'shopify_tools')) {
                    $table->boolean('shopify_tools')->default(false);
                }
                if (! Schema::hasColumn('ai_chat_assistants', 'inbox_agent_id')) {
                    $table->unsignedBigInteger('inbox_agent_id')->nullable()->index();
                }
                if (! Schema::hasColumn('ai_chat_assistants', 'channel_control')) {
                    $table->json('channel_control')->nullable();
                }
            });
        }

        if (Schema::hasTable('ai_agents')) {
            Schema::table('ai_agents', function (Blueprint $table) {
                if (! Schema::hasColumn('ai_agents', 'channel_whatsapp')) {
                    $table->boolean('channel_whatsapp')->default(true);
                }
                if (! Schema::hasColumn('ai_agents', 'channel_facebook')) {
                    $table->boolean('channel_facebook')->default(false);
                }
                if (! Schema::hasColumn('ai_agents', 'channel_instagram')) {
                    $table->boolean('channel_instagram')->default(false);
                }
                if (! Schema::hasColumn('ai_agents', 'channel_tiktok')) {
                    $table->boolean('channel_tiktok')->default(false);
                }
                if (! Schema::hasColumn('ai_agents', 'channel_control')) {
                    $table->json('channel_control')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_chat_assistants')) {
            Schema::table('ai_chat_assistants', function (Blueprint $table) {
                foreach (['business_brief', 'channel_whatsapp', 'channel_facebook', 'channel_instagram', 'channel_tiktok', 'shopify_tools', 'inbox_agent_id', 'channel_control'] as $col) {
                    if (Schema::hasColumn('ai_chat_assistants', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
        if (Schema::hasTable('ai_agents')) {
            Schema::table('ai_agents', function (Blueprint $table) {
                foreach (['channel_whatsapp', 'channel_facebook', 'channel_instagram', 'channel_tiktok', 'channel_control'] as $col) {
                    if (Schema::hasColumn('ai_agents', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
