<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign Follow-ups — automation attached to a WhatsApp campaign.
 *
 * After a campaign sends, each recipient is matched against these rules based on
 * how they engaged (replied / clicked / read-but-no-reply-in-X / delivered /
 * failed…) and an action fires for them (start a flow, send a template, add a
 * tag, enrol a drip, assign an agent).
 *
 * Two tables:
 *   campaign_followups     — the RULES an operator defines on a campaign.
 *   campaign_followup_runs — one row per (rule × recipient): the idempotency
 *                            guard for immediate fires AND the durable due-queue
 *                            for time-delayed fires (mirrors drip_subscribers.
 *                            next_send_at — a pending action is a ROW WITH A
 *                            TIMESTAMP, never a timer in RAM).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_followups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id')->index();
            $table->unsignedBigInteger('workspace_id')->index();

            // WHEN the rule fires. Immediate events land from the webhook the
            // moment Meta reports them; the *_no_* events are time-delayed and
            // evaluated by CampaignFollowupSweeper once `delay_minutes` elapses.
            //   replied | clicked_button | clicked_link | read
            //   delivered_no_read | read_no_reply | sent_no_reply | not_delivered
            //   failed
            $table->string('trigger_event', 32);

            // Null / 0 = immediate. Otherwise minutes after the recipient was
            // SENT, after which the negative condition is re-checked and fired.
            $table->unsignedInteger('delay_minutes')->nullable();

            // Optional extra match (e.g. a specific button payload).
            $table->json('condition_json')->nullable();

            // WHAT happens.
            //   start_flow | send_template | enroll_drip | send_message
            //   add_tag | remove_tag | assign_agent | add_to_campaign | opt_out
            $table->string('action_type', 32);
            // The target id for the action (flow_id | template_id | drip_id |
            // tag_id | campaign_id | agent_id) — meaning depends on action_type.
            $table->unsignedBigInteger('action_ref_id')->nullable();
            // Free-form payload (message body + var_map for send_message, etc.).
            $table->json('action_payload_json')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['campaign_id', 'is_active']);
            $table->index(['trigger_event', 'is_active']);
        });

        Schema::create('campaign_followup_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_followup_id')->index();
            $table->unsignedBigInteger('wp_campaign_contact_id')->index();
            $table->unsignedBigInteger('contact_id')->nullable();

            // Null for an immediate rule (fired at the webhook). For a delayed
            // rule this is sent_at + delay_minutes — the durable due timestamp
            // the sweeper scans, exactly like drip_subscribers.next_send_at.
            $table->timestamp('due_at')->nullable();

            $table->string('status', 20)->default('pending'); // pending|fired|skipped|failed
            $table->timestamp('fired_at')->nullable();
            $table->json('result_json')->nullable();          // wamid / flow_subscriber_id / error

            $table->timestamps();

            // The sweeper's hot path: "which delayed runs are due now?"
            $table->index(['status', 'due_at']);
            // One fire per (rule, recipient) — the whole dedupe guarantee.
            $table->unique(['campaign_followup_id', 'wp_campaign_contact_id'], 'cfr_rule_recipient_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_followup_runs');
        Schema::dropIfExists('campaign_followups');
    }
};
