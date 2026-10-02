<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-number "Business / segment tag" — when set on a number, every incoming
 * conversation AND its contact are auto-tagged with this business, so contacts
 * are segmented by which number/business they belong to. Lives on both device
 * stores: `devices` (Unofficial) and `wa_provider_configs` (WABA / Twilio).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('devices') && ! Schema::hasColumn('devices', 'default_tag')) {
            Schema::table('devices', fn (Blueprint $t) => $t->string('default_tag', 60)->nullable());
        }
        if (Schema::hasTable('wa_provider_configs') && ! Schema::hasColumn('wa_provider_configs', 'default_tag')) {
            Schema::table('wa_provider_configs', fn (Blueprint $t) => $t->string('default_tag', 60)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('devices') && Schema::hasColumn('devices', 'default_tag')) {
            Schema::table('devices', fn (Blueprint $t) => $t->dropColumn('default_tag'));
        }
        if (Schema::hasTable('wa_provider_configs') && Schema::hasColumn('wa_provider_configs', 'default_tag')) {
            Schema::table('wa_provider_configs', fn (Blueprint $t) => $t->dropColumn('default_tag'));
        }
    }
};
