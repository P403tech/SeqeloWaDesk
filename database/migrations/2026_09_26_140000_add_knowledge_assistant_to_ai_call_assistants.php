<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give the AI CALL (voice) assistant an optional knowledge base — an AI-Training
 * assistant (ai_chat_assistants) whose trained sources are stitched into the
 * voice agent's system prompt at call start, so it answers from that knowledge
 * during the call. Loaded once (context-stuffed) rather than per-turn RAG, so
 * the reply stays fast + smooth on a live call.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_call_assistants') && ! Schema::hasColumn('ai_call_assistants', 'knowledge_assistant_id')) {
            Schema::table('ai_call_assistants', fn (Blueprint $t) => $t->unsignedBigInteger('knowledge_assistant_id')->nullable()->after('ai_system_prompt'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_call_assistants') && Schema::hasColumn('ai_call_assistants', 'knowledge_assistant_id')) {
            Schema::table('ai_call_assistants', fn (Blueprint $t) => $t->dropColumn('knowledge_assistant_id'));
        }
    }
};
