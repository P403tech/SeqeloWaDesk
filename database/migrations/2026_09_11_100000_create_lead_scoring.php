<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead scoring — the engine behind the AI SDR's "lead qualification + lead
 * scoring" and "stop when a lead converts" capabilities.
 *
 *   contacts.lead_score        running numeric score (0..100), driven by rules
 *   contacts.lead_grade        A/B/C/D bucket derived from the score
 *   lead_scoring_rules         per-workspace rules: signal + points + optional
 *                              conditions (same [field,op,value] shape as
 *                              routing_rules), so a signal like an inbound reply
 *                              or a booked meeting adds/subtracts points
 *   lead_score_events          per-contact audit trail of every score change,
 *                              so the SDR dashboard can explain WHY a lead scored
 *
 * All idempotent + reversible. MySQL/MariaDB + SQLite (tests) safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contacts')) {
            Schema::table('contacts', function (Blueprint $t) {
                if (! Schema::hasColumn('contacts', 'lead_score')) {
                    $t->integer('lead_score')->default(0)->index()->after('id');
                }
                if (! Schema::hasColumn('contacts', 'lead_grade')) {
                    $t->string('lead_grade', 2)->nullable()->after('lead_score');
                }
                if (! Schema::hasColumn('contacts', 'lead_score_updated_at')) {
                    $t->timestamp('lead_score_updated_at')->nullable()->after('lead_grade');
                }
            });
        }

        if (! Schema::hasTable('lead_scoring_rules')) {
            Schema::create('lead_scoring_rules', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->string('name');
                // The event that triggers the rule (see LeadScoringService::SIGNALS).
                $t->string('signal', 64)->index();
                // Points to add (may be negative, e.g. an unsubscribe).
                $t->integer('points')->default(0);
                // Optional [[field, op, value], …] AND-ed conditions against the
                // signal context (same shape routing_rules uses). null = always.
                $t->json('conditions')->nullable();
                $t->boolean('is_active')->default(true);
                $t->integer('sort')->default(0);
                $t->unsignedInteger('fired_count')->default(0);
                $t->timestamp('last_fired_at')->nullable();
                $t->timestamps();
                $t->index(['workspace_id', 'signal', 'is_active'], 'lsr_ws_signal_active');
            });
        }

        if (! Schema::hasTable('lead_score_events')) {
            Schema::create('lead_score_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->unsignedBigInteger('contact_id')->index();
                $t->unsignedBigInteger('rule_id')->nullable();
                $t->string('signal', 64);
                $t->integer('points');
                $t->integer('score_after');
                $t->json('context')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['contact_id', 'created_at'], 'lse_contact_created');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_score_events');
        Schema::dropIfExists('lead_scoring_rules');
        if (Schema::hasTable('contacts')) {
            Schema::table('contacts', function (Blueprint $t) {
                foreach (['lead_score', 'lead_grade', 'lead_score_updated_at'] as $col) {
                    if (Schema::hasColumn('contacts', $col)) {
                        $t->dropColumn($col);
                    }
                }
            });
        }
    }
};
