<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website AI chat → conversational lead capture. When on, after each AI reply
 * the bot extracts the visitor's details (name/email/phone + the workspace's
 * custom attributes) from the conversation and merges them onto the linked
 * Contact — so the AI chat doubles as a form, and the AI can answer using what
 * it captured.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chatbot_widgets') && ! Schema::hasColumn('chatbot_widgets', 'ai_capture_enabled')) {
            Schema::table('chatbot_widgets', fn (Blueprint $t) => $t->boolean('ai_capture_enabled')->default(false)->after('collect_phone'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('chatbot_widgets') && Schema::hasColumn('chatbot_widgets', 'ai_capture_enabled')) {
            Schema::table('chatbot_widgets', fn (Blueprint $t) => $t->dropColumn('ai_capture_enabled'));
        }
    }
};
