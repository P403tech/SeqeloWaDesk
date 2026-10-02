<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-number "Default AI agent" — when set on a number, every new inbound
 * conversation on that number is auto-assigned to this AI agent (and answered),
 * with no manual per-chat assignment and no routing rule. Lives on BOTH device
 * stores: `devices` (Unofficial API) and `wa_provider_configs` (WABA / Twilio).
 * Nullable = off.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('devices') && ! Schema::hasColumn('devices', 'default_ai_agent_id')) {
            Schema::table('devices', fn (Blueprint $t) => $t->unsignedBigInteger('default_ai_agent_id')->nullable());
        }
        if (Schema::hasTable('wa_provider_configs') && ! Schema::hasColumn('wa_provider_configs', 'default_ai_agent_id')) {
            Schema::table('wa_provider_configs', fn (Blueprint $t) => $t->unsignedBigInteger('default_ai_agent_id')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('devices') && Schema::hasColumn('devices', 'default_ai_agent_id')) {
            Schema::table('devices', fn (Blueprint $t) => $t->dropColumn('default_ai_agent_id'));
        }
        if (Schema::hasTable('wa_provider_configs') && Schema::hasColumn('wa_provider_configs', 'default_ai_agent_id')) {
            Schema::table('wa_provider_configs', fn (Blueprint $t) => $t->dropColumn('default_ai_agent_id'));
        }
    }
};
