<?php

namespace App\Services\Crm;

use App\Models\Deal;
use App\Models\DealActivity;
use App\Models\Flow;
use App\Services\Flow\FlowEnrollmentService;
use Illuminate\Support\Facades\Log;

/**
 * "No activity for X hours/days" — the one CRM trigger with no natural event to
 * hang off. Nothing HAPPENS when a deal goes quiet, so silence has to be swept
 * for.
 *
 * Project policy is NO scheduler dependency, so this runs the same two ways as
 * the reminder sweeps, both safe to repeat:
 *   - inline on the Team-Inbox AJAX poll (cache-gated per workspace)
 *   - the `crm:idle-sweep` artisan command, for hosts that DO run cron
 *
 * WHAT COUNTS AS ACTIVITY
 * A deal is idle when BOTH its own updated_at and its newest deal_activities row
 * are older than the window. Using updated_at alone would be wrong: logging a
 * call against a deal writes an activity row without touching the deal, so an
 * actively-worked deal would look abandoned.
 *
 * WHY THE STAMP
 * Enrolment is idempotent per (flow, contact), but that is not enough here: a
 * contact who finishes or leaves the flow becomes enrollable again, and this
 * sweep would re-fire on the very next tick because the deal is still idle. So
 * each deal records the window it last fired for in deals.meta->idle_fired_at,
 * keyed BY WINDOW — "nudge at 48h" and "escalate at 168h" are different events
 * on the same deal and must both be able to fire. The stamp clears whenever the
 * deal is worked again (see DealIdleSweepService::clearFor).
 */
class DealIdleSweepService
{
    /** Deals examined per run — matches TaskReminderService's ceiling. */
    private const LIMIT = 200;

    /**
     * @param  int|null  $workspaceId  null = every workspace with a no_activity flow
     * @return int  number of deals enrolled
     */
    public function sweep(?int $workspaceId = null, int $limit = self::LIMIT): int
    {
        // Only workspaces that actually configured the trigger are scanned, and
        // only for the exact windows they configured. A workspace with no
        // no_activity flow costs one indexed query and stops here.
        $flows = Flow::query()
            ->where('is_active', true)
            ->where('is_published', true)
            ->where('trigger_kind', 'no_activity')
            ->when($workspaceId, fn ($q) => $q->where('workspace_id', $workspaceId))
            ->get(['workspace_id', 'trigger_value']);

        if ($flows->isEmpty()) {
            return 0;
        }

        $enroll = app(FlowEnrollmentService::class);
        $fired  = 0;

        // Group by workspace so several windows in one workspace share a pass.
        foreach ($flows->groupBy('workspace_id') as $wsId => $rows) {
            $windows = $rows->pluck('trigger_value')
                ->map(fn ($v) => (int) $v)
                ->filter(fn ($v) => $v > 0)
                ->unique()
                // Longest window first: a deal idle for 200h satisfies both the
                // 48h and 168h flows, and the escalation is the more meaningful
                // of the two. Firing that one first means the shorter window's
                // stamp check below cannot pre-empt it.
                ->sortDesc()
                ->values();

            foreach ($windows as $hours) {
                $fired += $this->sweepWindow((int) $wsId, (int) $hours, $enroll, $limit);
            }
        }

        return $fired;
    }

    /** Enrol every deal in one workspace that has been idle for exactly this window. */
    private function sweepWindow(int $wsId, int $hours, FlowEnrollmentService $enroll, int $limit): int
    {
        $cutoff = now()->subHours($hours);

        $deals = Deal::query()
            ->where('workspace_id', $wsId)
            ->where('status', 'open')          // a won/lost deal is finished, not neglected
            ->whereNotNull('contact_id')       // no contact = nobody to message
            ->where('updated_at', '<', $cutoff)
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        $fired = 0;
        foreach ($deals as $deal) {
            try {
                // Activity on the deal counts even when the deal row itself was
                // not touched — logging a call or completing a task writes here.
                $lastActivity = DealActivity::where('deal_id', $deal->id)->max('created_at');
                if ($lastActivity && \Illuminate\Support\Carbon::parse($lastActivity)->gte($cutoff)) {
                    continue;
                }

                $meta   = is_array($deal->meta) ? $deal->meta : [];
                $stamps = is_array($meta['idle_fired_at'] ?? null) ? $meta['idle_fired_at'] : [];

                // Already fired for THIS window since the deal last moved? Skip.
                // Compared against the deal's own updated_at so the stamp
                // self-clears the moment someone works the deal again — no
                // separate reset pass to keep in sync.
                $prev = $stamps[(string) $hours] ?? null;
                if ($prev && \Illuminate\Support\Carbon::parse($prev)->gte($deal->updated_at)) {
                    continue;
                }

                $enroll->onNoActivity($deal, $hours);

                // Stamp WITHOUT touching updated_at — a normal save would bump
                // it, which would make the deal look freshly worked and reset
                // every other idle window on it.
                $stamps[(string) $hours] = now()->toIso8601String();
                $meta['idle_fired_at']   = $stamps;
                Deal::withoutTimestamps(fn () => Deal::whereKey($deal->id)->update(['meta' => json_encode($meta)]));

                $fired++;
            } catch (\Throwable $e) {
                Log::warning('[CRM] idle sweep failed (deal ' . $deal->id . '): ' . $e->getMessage());
            }
        }

        return $fired;
    }
}
