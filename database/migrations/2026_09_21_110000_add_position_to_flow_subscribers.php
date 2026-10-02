<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop-off funnel (flow analytics Phase 2). Records WHERE a run currently sits
 * so /flows/analytics can show which wait node (question / buttons / list)
 * customers stall at. The Node runtime stamps these at each wait-node park via
 * POST /api/flow-node/position:
 *
 *   current_node_id     the wait node the run is parked on (its builder id)
 *   current_node_label  a human label for that node (question text, truncated)
 *                       — denormalized so the analytics query never parses the
 *                       flow_data JSON
 *   last_advanced_at    when it last moved — old + still 'active' = abandoned
 *
 * All nullable + additive: existing rows and every non-enroll flow start (which
 * has no subscriber row) are unaffected.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('flow_subscribers', function (Blueprint $t) {
            if (!Schema::hasColumn('flow_subscribers', 'current_node_id')) {
                $t->string('current_node_id', 64)->nullable()->after('status');
            }
            if (!Schema::hasColumn('flow_subscribers', 'current_node_label')) {
                $t->string('current_node_label', 191)->nullable()->after('current_node_id');
            }
            if (!Schema::hasColumn('flow_subscribers', 'last_advanced_at')) {
                $t->timestamp('last_advanced_at')->nullable()->after('current_node_label');
            }
        });

        // Funnel query filters by flow + status then groups by node — index the
        // lead columns. Guarded so a re-run does not collide.
        try {
            Schema::table('flow_subscribers', function (Blueprint $t) {
                $t->index(['flow_id', 'status'], 'flow_subs_flow_status_idx');
            });
        } catch (\Throwable $e) {
            // Index already exists (partial prior run) — safe to ignore.
        }
    }

    public function down(): void
    {
        Schema::table('flow_subscribers', function (Blueprint $t) {
            try { $t->dropIndex('flow_subs_flow_status_idx'); } catch (\Throwable $e) {}
            foreach (['current_node_id', 'current_node_label', 'last_advanced_at'] as $col) {
                if (Schema::hasColumn('flow_subscribers', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
