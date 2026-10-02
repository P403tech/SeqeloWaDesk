<?php

namespace App\Console\Commands;

use App\Services\Crm\DealIdleSweepService;
use Illuminate\Console\Command;

/**
 * Fires the `no_activity` flow trigger for deals that have gone quiet.
 *
 * Silence has no event to hang off, so it has to be swept for. Mirrors
 * CrmRemindTasksCommand: TeamInboxController::queue() also runs this inline
 * (cache-gated per workspace), so hosts without cron still get it while an
 * operator has the inbox open. Idempotent — DealIdleSweepService stamps each
 * deal per idle window, and the stamp self-clears when the deal is worked again.
 */
class CrmIdleSweepCommand extends Command
{
    protected $signature = 'crm:idle-sweep {--limit=200}';
    protected $description = 'Enrol deals that have had no activity for their configured window into no_activity flows.';

    public function handle(DealIdleSweepService $svc): int
    {
        $n = $svc->sweep(null, (int) $this->option('limit'));
        $this->info("Enrolled {$n} idle deal(s).");

        return self::SUCCESS;
    }
}
