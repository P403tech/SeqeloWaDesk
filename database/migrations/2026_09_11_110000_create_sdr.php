<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI SDR orchestrator.
 *
 * An SDR campaign wraps an existing cadence FLOW (which already handles the
 * multi-step, multi-channel, AI-personalised outreach + delays + booking) with
 * the SDR-specific layer: who to enrol (min score / conditions), when to hand
 * off to a human sales team (route_score threshold), and when to stop chasing
 * (reply / conversion). sdr_enrollments tracks each lead through it.
 *
 * Idempotent + reversible; MySQL/MariaDB + SQLite (tests) safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sdr_campaigns')) {
            Schema::create('sdr_campaigns', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->string('name');
                $t->boolean('is_active')->default(true);
                // The cadence flow that does the actual outreach (nullable so a
                // campaign can exist before its flow is chosen).
                $t->unsignedBigInteger('flow_id')->nullable();
                // Only enrol leads scoring at least this.
                $t->integer('enroll_min_score')->default(0);
                // Hand off to a human when the lead reaches this score (null = never).
                $t->integer('route_score')->nullable();
                // Team the qualified lead is routed to.
                $t->unsignedBigInteger('route_team_id')->nullable();
                // Stop the cadence when the lead replies / converts.
                $t->boolean('stop_on_reply')->default(true);
                $t->boolean('stop_on_convert')->default(true);
                // Optional [[field,op,value],…] enrol gate (same shape as scoring rules).
                $t->json('enroll_conditions')->nullable();
                $t->timestamps();
                $t->index(['workspace_id', 'is_active'], 'sdr_ws_active');
            });
        }

        if (! Schema::hasTable('sdr_enrollments')) {
            Schema::create('sdr_enrollments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->unsignedBigInteger('sdr_campaign_id')->index();
                $t->unsignedBigInteger('contact_id')->index();
                // active | routed | converted | stopped | completed
                $t->string('status', 16)->default('active');
                $t->integer('score_at_enroll')->default(0);
                $t->timestamp('enrolled_at')->nullable();
                $t->timestamp('routed_at')->nullable();
                $t->timestamp('stopped_at')->nullable();
                $t->string('stopped_reason', 40)->nullable();
                $t->timestamps();
                $t->unique(['sdr_campaign_id', 'contact_id'], 'sdr_enr_unique');
                $t->index(['sdr_campaign_id', 'status'], 'sdr_enr_status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sdr_enrollments');
        Schema::dropIfExists('sdr_campaigns');
    }
};
