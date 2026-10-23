<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_chat_assistants') && !Schema::hasColumn('ai_chat_assistants', 'web_search')) {
            Schema::table('ai_chat_assistants', function (Blueprint $table) {
                $table->boolean('web_search')->default(false)->after('handoff_message');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ai_chat_assistants', 'web_search')) {
            Schema::table('ai_chat_assistants', function (Blueprint $table) {
                $table->dropColumn('web_search');
            });
        }
    }
};
