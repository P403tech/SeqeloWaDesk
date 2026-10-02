<?php

namespace App\Console\Commands;

use App\Models\MetaLeadForm;
use App\Services\Facebook\MetaLeadSyncService;
use Illuminate\Console\Command;

/**
 * On-demand Meta Lead Ads backfill.
 *
 * The sweep normally rides on the /lead-ads page load (project policy is
 * no `schedule:run`), which is enough while someone is using the system. This
 * command is the support hatch for the case that policy does not cover: a
 * workspace whose operators have not opened the page for weeks, with Meta's
 * ~90-day lead deletion running the whole time. A host who wants a stricter
 * cadence can call it from their own cron.
 */
class MetaLeadsSweepCommand extends Command
{
    protected $signature = 'meta:leads-sweep
                            {--workspace= : Only this workspace id}
                            {--days=7 : How far back to look on a form that has never been swept}';

    protected $description = 'Import Meta lead-ad submissions the realtime webhook never delivered';

    public function handle(MetaLeadSyncService $sync): int
    {
        $days  = max(1, (int) $this->option('days'));
        $forms = MetaLeadForm::query()
            ->where('enabled', true)
            ->when($this->option('workspace'), fn ($q) => $q->where('workspace_id', (int) $this->option('workspace')))
            ->with('page')
            ->get();

        if ($forms->isEmpty()) {
            $this->info('No enabled lead forms to sweep.');

            return self::SUCCESS;
        }

        $totalNew = 0;
        $failed   = 0;

        foreach ($forms as $form) {
            try {
                $res = $sync->backfillForm($form, $days);
                if (! ($res['ok'] ?? false)) {
                    $failed++;
                    $this->warn(sprintf('  %-40s %s', $form->name, $res['error'] ?? 'failed'));
                    continue;
                }

                $totalNew += (int) $res['new'];
                $this->line(sprintf('  %-40s seen %-4d new %d', $form->name, $res['seen'], $res['new']));
            } catch (\Throwable $e) {
                // One broken form must not stop the rest — a revoked token on a
                // single Page is the common case.
                $failed++;
                $this->warn(sprintf('  %-40s %s', $form->name, $e->getMessage()));
            }
        }

        $this->info(sprintf('Swept %d form(s), imported %d lead(s)%s.',
            $forms->count(), $totalNew, $failed ? ", $failed failed" : ''));

        return $failed && $totalNew === 0 ? self::FAILURE : self::SUCCESS;
    }
}
