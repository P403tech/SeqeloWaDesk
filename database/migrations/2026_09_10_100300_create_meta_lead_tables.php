<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta Lead Ads / Instant Forms.
 *
 * The Graph calls to list forms and pull leads already existed in
 * FacebookPageClient — they were simply never invoked, and there was nowhere to
 * put the result. These two tables are that missing half:
 *
 *   meta_lead_forms — one row per Instant Form, plus how this workspace wants
 *                     its leads routed (field map, pipeline, stage, owner).
 *   meta_leads      — one row per submission, with FULL ad attribution, kept
 *                     even when ingest fails so nothing is ever lost.
 *
 * `leadgen_id` is UNIQUE. That single constraint is what makes the realtime
 * webhook and the backfill sweep safe to run against each other: whichever
 * arrives second is a no-op instead of a duplicate contact and a duplicate deal.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('meta_lead_forms')) {
            Schema::create('meta_lead_forms', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->unsignedBigInteger('facebook_page_id')->index();  // facebook_pages.id
                $t->string('form_id', 64)->index();                   // Meta leadgen_form id
                $t->string('name', 191);
                $t->string('status', 24)->default('ACTIVE');
                $t->string('locale', 16)->nullable();
                // Meta's questions[] snapshot — the source list the mapping UI
                // shows on the left. Refreshed whenever the forms are re-listed.
                $t->json('questions')->nullable();
                // { meta_question_key: {target: contact|contact_custom|deal_custom, key: '...'} }
                $t->json('field_map')->nullable();

                // Routing. A form the operator has not configured yet still
                // captures leads (see the placeholder path in the ingest
                // service); `enabled` controls whether it ACTS on them.
                $t->boolean('enabled')->default(true);
                $t->boolean('create_deal')->default(true);
                $t->unsignedBigInteger('pipeline_id')->nullable();
                $t->unsignedBigInteger('stage_id')->nullable();
                $t->unsignedBigInteger('owner_user_id')->nullable();
                $t->unsignedBigInteger('owner_team_id')->nullable();
                $t->string('assign_strategy', 16)->default('fixed');  // fixed | round_robin
                $t->unsignedBigInteger('flow_id')->nullable();        // optional welcome flow
                $t->json('tag_ids')->nullable();

                // Backfill bookkeeping. Meta deletes leads after ~90 days, so a
                // webhook outage loses them permanently without this sweep.
                $t->timestamp('last_synced_at')->nullable();
                $t->string('sync_cursor', 191)->nullable();
                $t->timestamps();

                $t->unique(['workspace_id', 'form_id']);
            });
        }

        if (! Schema::hasTable('meta_leads')) {
            Schema::create('meta_leads', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                // Nullable on purpose: a lead can be stored BEFORE its form is
                // known — a Graph fetch failure is recorded against the row so
                // it stays retryable, and that path has no form to point at yet.
                $t->unsignedBigInteger('meta_lead_form_id')->nullable()->index();

                // THE dedupe key. Webhook and backfill both write through it.
                $t->string('leadgen_id', 64)->unique();

                // Attribution — exactly the fields getFormLeads() already asks
                // Meta for. Indexed where a report would group on them.
                $t->string('form_id', 64)->nullable();
                $t->string('ad_id', 64)->nullable()->index();
                $t->string('ad_name', 191)->nullable();
                $t->string('adset_id', 64)->nullable()->index();
                $t->string('adset_name', 191)->nullable();
                $t->string('campaign_id', 64)->nullable()->index();
                $t->string('campaign_name', 191)->nullable();
                $t->string('platform', 24)->nullable();   // fb | ig
                $t->boolean('is_organic')->default(false);

                // The customer's answers. PII, so encrypted at rest via the
                // model's `encrypted:array` cast — hence text, not json.
                $t->text('field_data')->nullable();

                $t->unsignedBigInteger('contact_id')->nullable()->index();
                $t->unsignedBigInteger('deal_id')->nullable()->index();

                // pending → the row exists but the answers/routing have not been
                // applied yet. That state is what makes a failed ingest
                // RETRYABLE instead of a lost lead.
                $t->string('ingest_status', 16)->default('pending')->index();
                $t->string('ingest_error', 255)->nullable();
                $t->timestamp('submitted_at')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_leads');
        Schema::dropIfExists('meta_lead_forms');
    }
};
