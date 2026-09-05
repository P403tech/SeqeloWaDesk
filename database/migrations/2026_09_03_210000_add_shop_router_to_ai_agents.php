<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ai_agents', function (Blueprint $t) {
            if (!Schema::hasColumn('ai_agents', 'shop_router')) {
                $t->boolean('shop_router')->default(false)->after('use_saved_replies');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_agents', function (Blueprint $t) {
            if (Schema::hasColumn('ai_agents', 'shop_router')) {
                $t->dropColumn('shop_router');
            }
        });
    }
};
