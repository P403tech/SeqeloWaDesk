<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable flow SESSIONS (full durability). The Node flow runtime keeps every
 * running session in memory (`activeFlowSessions`), so a Node restart
 * (deploy/crash) dropped a flow that was PARKED waiting for the customer's
 * reply — their next message then found no session and was silently ignored,
 * stranding the flow.
 *
 * This table snapshots a parked session so the reply can rehydrate + continue
 * after a restart. One row per session_key (upsert on each park; cleared when
 * the run advances past the park or ends), so the table only ever holds the set
 * of currently-parked sessions — it does not grow unbounded.
 *
 *   waiting    = the full waitingForInput descriptor (nodeId, nextNodeType,
 *                variable, list/answer items, booking/commerce context, …) —
 *                everything handleFlowResponse needs to interpret the reply.
 *   variables  = session.userVariables (every answer already collected).
 *
 * Long delays are handled separately by flow_delay_resumes (time-based, not
 * reply-based).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('flow_parked_sessions', function (Blueprint $t) {
            $t->id();
            $t->string('session_key', 191)->unique(); // `${devicePhone}_${customerPhone}` (digits)
            $t->unsignedBigInteger('flow_id')->nullable();
            $t->unsignedBigInteger('flow_subscriber_id')->nullable();
            $t->string('device_phone', 32);
            $t->string('customer_phone', 32);
            $t->string('node_id', 64);
            $t->json('waiting')->nullable();
            $t->json('variables')->nullable();
            $t->string('provider', 24)->nullable();
            $t->timestamps();

            $t->index('flow_id', 'fps_flow_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_parked_sessions');
    }
};
