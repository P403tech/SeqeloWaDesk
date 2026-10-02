<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Clean up flows + keyword rules left behind by deleted workspaces.
 *
 * Until Workspace::deleted cascaded (same release), trashing a workspace left
 * its flows with deleted_at = NULL, so Flow::deleted never fired and the
 * managed keyword_replies rows those flows own stayed ACTIVE. Those dead rules
 * keep competing in the inbound keyword matcher, which is what makes "we have
 * 15 flows and the wrong one fires" look random.
 *
 * The model hooks stop it recurring. This clears what is already there — as a
 * MIGRATION rather than an artisan command, because customers run updates, not
 * commands. Nobody has to be told to do anything.
 *
 * Soft-deletes only: flows stay restorable, and a rule is disabled rather than
 * dropped. Nothing here destroys customer data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workspaces')) {
            return;
        }

        // Workspace ids that are alive. A soft-deleted workspace is NOT alive —
        // its automations should be dormant too.
        $live = DB::table('workspaces')
            ->whereNull('deleted_at')
            ->pluck('id')
            ->all();

        if (empty($live)) {
            return;   // nothing to compare against; do not touch anything
        }

        $flows = 0;
        $rules = 0;

        // ── Flows whose workspace is gone ────────────────────────────────
        if (Schema::hasTable('flows') && Schema::hasColumn('flows', 'deleted_at')) {
            try {
                $flows = DB::table('flows')
                    ->whereNull('deleted_at')
                    ->whereNotIn('workspace_id', $live)
                    ->update(['deleted_at' => now()]);
            } catch (\Throwable $e) {
                Log::warning('[MIGRATION] orphan flow cleanup skipped: ' . $e->getMessage());
            }
        }

        // ── Their managed keyword rules, plus any hand-made rule on a dead
        //    workspace. Disabled, not deleted — a workspace restore should be
        //    able to bring the automation back.
        if (Schema::hasTable('keyword_replies')) {
            try {
                $q = DB::table('keyword_replies')->whereNotIn('workspace_id', $live);

                $rules = Schema::hasColumn('keyword_replies', 'status')
                    ? $q->where('status', 'active')->update(['status' => 'inactive'])
                    : 0;
            } catch (\Throwable $e) {
                Log::warning('[MIGRATION] orphan keyword-rule cleanup skipped: ' . $e->getMessage());
            }
        }

        if ($flows > 0 || $rules > 0) {
            Log::info('[MIGRATION] orphaned automations cleaned', [
                'flows_trashed'  => $flows,
                'rules_disabled' => $rules,
            ]);
        }
    }

    public function down(): void
    {
        // Not reversible: we cannot tell which rows this migration touched from
        // ones the operator trashed themselves, and un-trashing them would
        // re-introduce the mis-firing this exists to stop.
    }
};
