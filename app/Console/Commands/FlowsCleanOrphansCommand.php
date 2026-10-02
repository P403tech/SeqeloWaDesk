<?php

namespace App\Console\Commands;

use App\Models\Flow;
use App\Models\KeywordReply;
use App\Models\Workspace;
use Illuminate\Console\Command;

/**
 * One-off repair for flows left behind by a deleted workspace.
 *
 * Until Workspace::deleted cascaded, trashing a workspace left its flows with
 * deleted_at = NULL, so Flow::deleted never ran and the managed keyword rules
 * those flows own stayed ACTIVE. Symptom in the field: "we deleted the account
 * and workspace but the flows keep coming back", plus keyword flows that fire
 * unpredictably because dead rules still compete in the matcher.
 *
 * The model hooks stop it happening again; this cleans what is already there.
 *
 *   php artisan flows:clean-orphans            # report only
 *   php artisan flows:clean-orphans --fix      # actually clean
 */
class FlowsCleanOrphansCommand extends Command
{
    protected $signature = 'flows:clean-orphans {--fix : Apply the cleanup (otherwise dry-run)}';

    protected $description = 'Trash flows and keyword rules whose workspace no longer exists.';

    public function handle(): int
    {
        $apply = (bool) $this->option('fix');

        // Workspace ids that are alive. Anything referencing something outside
        // this set is orphaned — either the row was soft-deleted or it is gone.
        $liveWorkspaces = Workspace::query()->pluck('id')->all();

        $flows = Flow::query()
            ->whereNotIn('workspace_id', $liveWorkspaces)
            ->get(['id', 'workspace_id', 'flow_name', 'is_active', 'is_published']);

        $rules = KeywordReply::query()
            ->whereNotIn('workspace_id', $liveWorkspaces)
            ->get(['id', 'workspace_id', 'keyword', 'flow_id', 'status']);

        $this->newLine();
        $this->info('Orphan scan');
        $this->line('  live workspaces      : ' . count($liveWorkspaces));
        $this->line('  orphaned flows       : ' . $flows->count());
        $this->line('  orphaned keyword rows: ' . $rules->count());

        $activeRules = $rules->where('status', 'active')->count();
        if ($activeRules > 0) {
            $this->warn("  {$activeRules} of those keyword rules are still ACTIVE and can still match inbound messages.");
        }

        if ($flows->isEmpty() && $rules->isEmpty()) {
            $this->newLine();
            $this->info('Nothing to clean.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->newLine();
            $this->line('Dry run — nothing changed. Re-run with --fix to apply.');
            foreach ($flows->take(20) as $f) {
                $this->line("  flow #{$f->id} (ws {$f->workspace_id}) {$f->flow_name}");
            }
            if ($flows->count() > 20) {
                $this->line('  … and ' . ($flows->count() - 20) . ' more');
            }

            return self::SUCCESS;
        }

        // Soft-delete per row so Flow::deleted runs its own trigger-rule
        // cleanup — and so a restore is still possible if a workspace comes
        // back. Never force-delete here: this is customer data.
        $trashed = 0;
        foreach ($flows as $f) {
            try {
                $f->delete();
                $trashed++;
            } catch (\Throwable $e) {
                $this->error("  flow #{$f->id}: " . $e->getMessage());
            }
        }

        // Rules whose flow is already gone (or that were hand-made) won't be
        // touched by the hook above — disable them so they stop matching.
        $disabled = KeywordReply::query()
            ->whereNotIn('workspace_id', $liveWorkspaces)
            ->where('status', 'active')
            ->update(['status' => 'inactive']);

        $this->newLine();
        $this->info("Cleaned: {$trashed} flow(s) trashed, {$disabled} keyword rule(s) disabled.");
        $this->line('Flows are soft-deleted and can still be restored.');

        return self::SUCCESS;
    }
}
