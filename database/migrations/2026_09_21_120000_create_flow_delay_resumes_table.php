<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable long delays (flow analytics/runtime Phase 3). A flow's "wait N
 * hours/days, then continue" used to be an in-process `await setTimeout` on the
 * Node side — a Node restart (deploy/crash) dropped the pending resume and the
 * flow silently stalled forever. This table makes a LONG delay durable the same
 * way campaign_followup_runs.due_at is: the pending resume is a row with a
 * timestamp, so a restart delays it, never loses it.
 *
 * The Node runtime writes a row when it hits a long duration-delay node (via
 * POST /api/flow-node/delay-park) and returns instead of awaiting; the
 * heartbeat-driven FlowDelayResumeSweeper POSTs each due row back to Node
 * (/api/flow/resume-delay), which continues the flow from that node.
 *
 * SHORT delays keep the original in-process await unchanged — no row, no churn.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('flow_delay_resumes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('flow_id');
            // The enroll path's flow_subscribers row, when present (null for
            // campaign / mobile-chat flow starts). Lets the resume respect a run
            // that finished in the meantime.
            $t->unsignedBigInteger('flow_subscriber_id')->nullable();
            // The Node session key `${devicePhone}_${customerPhone}` (digits) and
            // the two phones — everything the resume needs to rebuild the session
            // if the process restarted and the in-memory session is gone.
            $t->string('session_key', 191);
            $t->string('device_phone', 32);
            $t->string('customer_phone', 32);
            // The delay node to resume FROM (continue via its default out-port).
            $t->string('node_id', 64);
            // Engine hint (waba/twilio/baileys) so the resumed session routes the
            // same way the original launch did.
            $t->string('provider', 24)->nullable();
            // Snapshot of session.userVariables so the resumed flow keeps every
            // answer the customer already gave. Bounded (flow answers), JSON.
            $t->json('variables')->nullable();

            $t->timestamp('resume_at');
            // pending → sending → done | failed | cancelled
            $t->string('status', 16)->default('pending');
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->timestamp('resumed_at')->nullable();
            $t->timestamps();

            // The sweep's hot query: due pending rows, oldest first.
            $t->index(['status', 'resume_at'], 'fdr_status_resume_idx');
            $t->index('flow_id', 'fdr_flow_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_delay_resumes');
    }
};
