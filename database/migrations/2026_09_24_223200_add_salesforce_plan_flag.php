<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            if (! Schema::hasColumn('packages', 'integration_salesforce')) {
                $table->boolean('integration_salesforce')->default(true)->after('integration_hubspot');
            }
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            if (Schema::hasColumn('packages', 'integration_salesforce')) {
                $table->dropColumn('integration_salesforce');
            }
        });
    }
};
