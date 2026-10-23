<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which Meta endpoint a WABA template campaign sends its MARKETING templates
 * through: null/'cloud' = standard Cloud API (/messages), 'marketing' = the
 * Marketing Messages API (/marketing_messages). Utility / authentication
 * templates always stay on Cloud API.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('wpcampaigns') || Schema::hasColumn('wpcampaigns', 'send_api')) return;
        Schema::table('wpcampaigns', function (Blueprint $table) {
            $table->string('send_api', 16)->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('wpcampaigns') || !Schema::hasColumn('wpcampaigns', 'send_api')) return;
        Schema::table('wpcampaigns', function (Blueprint $table) {
            $table->dropColumn('send_api');
        });
    }
};
