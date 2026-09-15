<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('workspaces', 'catalog_auto')) {
            Schema::table('workspaces', function (Blueprint $table) {
                $table->json('catalog_auto')->nullable()->after('default_engine');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('workspaces', 'catalog_auto')) {
            Schema::table('workspaces', function (Blueprint $table) {
                $table->dropColumn('catalog_auto');
            });
        }
    }
};
