<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which connected phone is the workspace's main catalog sender
 * (composite key: baileys:{deviceId} | waba:{configId} | twilio:{configId}).
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('workspaces', 'catalog_sender')) {
            Schema::table('workspaces', function (Blueprint $table) {
                $table->string('catalog_sender', 64)->nullable()->after('default_engine');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('workspaces', 'catalog_sender')) {
            Schema::table('workspaces', function (Blueprint $table) {
                $table->dropColumn('catalog_sender');
            });
        }
    }
};
