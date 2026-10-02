<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIM-based SMS gateway — a linked Android phone sends SMS through its own SIM.
 *
 *   device_sms_gateways : one row per linked phone (device_token = its identity;
 *                         fcm_token for push wake-ups; sims + quotas + status).
 *   device_sms_jobs     : the outbound queue. WaDesk enqueues a row (pending);
 *                         the app PULLs it, sends via the SIM, and REPORTs back
 *                         sent/failed/delivered.
 *
 * Idempotent + reversible; MySQL/MariaDB + SQLite (tests) safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('device_sms_gateways')) {
            Schema::create('device_sms_gateways', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->string('name')->nullable();
                $t->string('device_token', 80)->unique();      // identity + auth (X-Gateway-Token)
                $t->string('fcm_token', 512)->nullable();       // push wake-up
                $t->json('sims')->nullable();                   // [{slot,label,number}]
                $t->integer('default_sim_slot')->default(0);
                // pending (linked, app not registered yet) | online | offline
                $t->string('status', 16)->default('pending');
                $t->integer('battery')->nullable();
                $t->timestamp('last_seen_at')->nullable();
                $t->integer('per_sim_daily_quota')->default(0); // 0 = unlimited
                $t->integer('delay_ms')->default(1500);         // pace between sends
                $t->string('app_version', 32)->nullable();
                $t->timestamps();
                $t->index(['workspace_id', 'status'], 'dsg_ws_status');
            });
        }

        if (! Schema::hasTable('device_sms_jobs')) {
            Schema::create('device_sms_jobs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->unsignedBigInteger('gateway_id')->index();
                $t->string('to_number', 32);
                $t->text('body');
                $t->integer('sim_slot')->nullable();
                // pending | dispatched | sent | failed | delivered
                $t->string('status', 16)->default('pending');
                $t->string('error', 255)->nullable();
                $t->string('source', 24)->nullable();           // api | campaign | inbox | flow
                $t->string('source_ref', 64)->nullable();
                $t->timestamp('claimed_at')->nullable();
                $t->timestamp('sent_at')->nullable();
                $t->timestamp('delivered_at')->nullable();
                $t->timestamps();
                $t->index(['gateway_id', 'status'], 'dsj_gw_status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('device_sms_jobs');
        Schema::dropIfExists('device_sms_gateways');
    }
};
