<?php

namespace App\Services;

use App\Services\Flow\FlowDelayResumeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Fires due durable flow-delay resumes ("wait N hours/days, then continue").
 * Same no-cron pattern as CampaignFollowupSweeper: registered on the Node
 * heartbeat, single-flight guarded by a short cache lock, bounded batch per
 * tick so a burst of resumes drains steadily instead of blocking one request.
 *
 * The durability lives in flow_delay_resumes.resume_at — every pending resume
 * is a row with a timestamp, so a restart delays it, never loses it.
 */
class FlowDelayResumeSweeper
{
    public function sweep(int $limit = 100): int
    {
        $lock = Cache::lock('flow-delay-resume-sweep', 25);
        if (! $lock->get()) return 0;

        try {
            return app(FlowDelayResumeService::class)->drainDue($limit);
        } catch (\Throwable $e) {
            Log::warning('[FLOW-DELAY] sweep failed: ' . $e->getMessage());
            return 0;
        } finally {
            optional($lock)->release();
        }
    }
}
