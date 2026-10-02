<?php

namespace App\Services;

use App\Services\Campaign\CampaignFollowupService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Fires time-delayed campaign follow-ups ("no reply within X hours", "delivered
 * but not read", …). Same no-cron pattern as CampaignScheduleSweeper /
 * ScheduledMessageSweeper: registered on the Node heartbeat, single-flight
 * guarded by a short cache lock, and it does a BOUNDED batch per tick so a huge
 * campaign drains steadily instead of blocking one request.
 *
 * The durability lives in campaign_followup_runs.due_at — every pending action
 * is a row with a timestamp, so a restart delays it, never loses it.
 */
class CampaignFollowupSweeper
{
    public function sweep(int $limit = 100): int
    {
        $lock = Cache::lock('campaign-followup-sweep', 25);
        if (! $lock->get()) return 0;

        try {
            return app(CampaignFollowupService::class)->drainDue($limit);
        } catch (\Throwable $e) {
            Log::warning('[CAMPAIGN-FOLLOWUP] sweep failed: ' . $e->getMessage());
            return 0;
        } finally {
            optional($lock)->release();
        }
    }
}
