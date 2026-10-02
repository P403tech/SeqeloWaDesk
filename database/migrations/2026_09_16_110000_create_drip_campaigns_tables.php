<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drip campaigns — a standalone, DURABLE follow-up sequence engine.
 *
 * Flows can already express a drip (audience trigger + Wait nodes), but their
 * Wait is an in-memory setTimeout in the Node runtime: a deploy, crash or
 * reboot silently drops every pending follow-up. That is fine for a 5-minute
 * pause and unusable for a 3-day clinical follow-up.
 *
 * The difference here is `drip_subscribers.next_send_at`. Every pending step is
 * a ROW WITH A TIMESTAMP, not a timer in RAM. A restart loses nothing — the
 * drain simply picks up everything now due. Late is recoverable; lost is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drip_campaigns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('name', 191);
            $table->string('status', 20)->default('draft');      // draft | active | paused

            // Who gets enrolled, and when.
            //   manual          — operator picks contacts / a group
            //   contact_created — every new contact in the workspace
            //   tag_added       — trigger_value = tag name
            //   group_added     — trigger_value = contact group id
            //   deal_created    — a CRM deal is opened
            $table->string('trigger_type', 32)->default('manual');
            $table->string('trigger_value', 191)->nullable();

            // Which number sends. Null = the workspace's usual routing.
            $table->unsignedBigInteger('device_id')->nullable();
            $table->string('provider', 32)->nullable();

            // Delivery window. Sending a clinic reminder at 03:00 is worse than
            // sending it late, so a due step waits for the window to open.
            $table->string('timezone', 64)->default('UTC');
            $table->unsignedTinyInteger('quiet_start_hour')->nullable();   // e.g. 21
            $table->unsignedTinyInteger('quiet_end_hour')->nullable();     // e.g. 9

            // Stop conditions — the difference between a drip and spam.
            $table->boolean('stop_on_reply')->default(true);
            $table->boolean('stop_on_deal_won')->default(false);

            // Goal exit: the sequence exists to make something happen, and once
            // it has, continuing to chase is worse than useless. Without this a
            // "stop when they book" rule means someone remembering to untag by
            // hand — which nobody does.
            $table->string('goal_type', 32)->nullable();   // tag_added | appointment_booked | order_placed
            $table->string('goal_value', 191)->nullable(); // tag name, when goal_type = tag_added

            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['trigger_type', 'status']);
        });

        Schema::create('drip_campaign_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('drip_campaign_id')->index();
            $table->unsignedInteger('position')->default(1);

            // Wait BEFORE this step fires, measured from the previous step
            // (or from enrolment for position 1). 0 = send immediately.
            $table->unsignedInteger('delay_amount')->default(0);
            $table->string('delay_unit', 10)->default('hour');   // minute | hour | day

            $table->string('message_type', 20)->default('text'); // text | template
            $table->text('body')->nullable();                    // encrypted at the model
            $table->unsignedBigInteger('template_id')->nullable();

            // Which contact field fills each positional {{1}}, {{2}}… in the
            // chosen template. ['name','mobile'] means {{1}}=name, {{2}}=mobile.
            // Meta rejects a template whose parameter count does not match, so
            // this is what makes a template step actually deliverable.
            $table->json('var_map')->nullable();
            $table->string('media_path', 512)->nullable();
            $table->string('media_type', 32)->nullable();

            $table->timestamps();

            $table->index(['drip_campaign_id', 'position']);
        });

        Schema::create('drip_subscribers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('drip_campaign_id')->index();
            $table->unsignedBigInteger('contact_id')->index();
            $table->unsignedBigInteger('workspace_id')->index();

            $table->string('status', 20)->default('active');     // active | completed | stopped | failed
            $table->unsignedInteger('current_step')->default(0); // last position SENT

            // THE durability guarantee. A pending follow-up is this timestamp,
            // not a setTimeout — so it survives any restart.
            $table->timestamp('next_send_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();

            $table->string('stopped_reason', 191)->nullable();
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('fail_count')->default(0);

            // Retry bookkeeping for the CURRENT step. A transient fault — device
            // briefly offline, provider 500 — must not silently cost the contact
            // a message, so a failed step is retried with backoff rather than
            // skipped. Reset to 0 each time a step succeeds.
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('last_error', 191)->nullable();

            $table->timestamps();

            // The drain's hot path: "which subscribers are due right now?"
            $table->index(['status', 'next_send_at']);
            // One enrolment per contact per campaign.
            $table->unique(['drip_campaign_id', 'contact_id'], 'drip_sub_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drip_subscribers');
        Schema::dropIfExists('drip_campaign_steps');
        Schema::dropIfExists('drip_campaigns');
    }
};
