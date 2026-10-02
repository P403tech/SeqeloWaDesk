<?php

namespace App\Services\Deals;

use App\Models\Deal;
use App\Models\DealActivity;
use App\Services\Inbox\NotificationDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Sweeps deal tasks whose due_at has passed and nudges the deal owner once.
 *
 * Project policy is NO scheduler dependency — periodic work runs inline on
 * an existing AJAX poll. So this is called two ways, both safe to repeat:
 *   - TeamInboxController::queue() (cache-gated per workspace) — the primary
 *     path, fires whenever anyone in the workspace has the inbox open.
 *   - the `deals:remind-tasks` artisan command — for hosts that DO run cron.
 *
 * Idempotent via deal_activities.reminded_at: a task is picked up only while
 * reminded_at IS NULL, and we stamp it the moment we notify, so a task can
 * never produce two reminders no matter how often the sweep runs.
 */
class DealReminderService
{
    public function sweep(?int $workspaceId = null, int $limit = 200): int
    {
        $q = DealActivity::query()
            ->where('type', 'task')
            ->whereNull('done_at')
            ->whereNull('reminded_at')
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now())
            ->orderBy('due_at')
            ->limit($limit);

        if ($workspaceId) {
            $q->where('workspace_id', $workspaceId);
        }

        $tasks = $q->get();
        if ($tasks->isEmpty()) return 0;

        $disp  = app(NotificationDispatcher::class);
        $count = 0;

        foreach ($tasks as $task) {
            try {
                $deal = Deal::find($task->deal_id);
                if ($deal) {
                    $disp->notifyDealTaskDue($deal, $task);
                    // Follow-up date reached → fire the `task_due` flow trigger
                    // so the customer can be messaged, not just the owner. Rides
                    // this sweep's existing once-per-task guarantee (reminded_at
                    // is stamped just below), and is wrapped separately so a
                    // flow failure never blocks that stamp — an unstamped task
                    // would nag the owner on every sweep forever.
                    try {
                        app(\App\Services\Flow\FlowEnrollmentService::class)->onTaskDue($task);
                    } catch (\Throwable $e) {
                        Log::warning('[DEAL] task_due flow-trigger failed (activity ' . $task->id . '): ' . $e->getMessage());
                    }
                }
                // Stamp regardless — a missing/deleted deal shouldn't keep the
                // orphan task in the sweep forever.
                $task->forceFill(['reminded_at' => now()])->save();
                $count++;
            } catch (\Throwable $e) {
                Log::warning('[DEAL] task reminder failed (activity ' . $task->id . '): ' . $e->getMessage());
            }
        }

        return $count;
    }
}
