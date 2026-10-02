<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Project policy: NO `php artisan schedule:run` dependency. Every
// periodic job runs INLINE on an existing AJAX-poll endpoint instead.
// The artisan commands listed below still exist for on-demand /
// support invocation, but are NOT wired to Schedule::command:
//
//   - inbox:escalate       → swept by TeamInboxController::queue()
//                            (every ~5s while any operator polls)
//   - inbox:wake-snoozed   → swept by TeamInboxController::queue()
//                            (every ~5s, cache-gated to 30s/workspace)
//   - support:sla-scan     → swept by TeamInboxController::queue()
//                            (every ~5s, cache-gated to 60s/workspace)
//   - WABA template status → swept by TemplatesController::refresh()
//                            on every page-load + AJAX poll
//   - meta:leads-sweep     → swept by MetaLeadsController::index()
//                            (on /lead-ads load, cache-gated to 10min
//                            per workspace) — Meta deletes leads after ~90
//                            days, so a host wanting a stricter cadence can
//                            cron this command themselves
//
// Trade-off: workspaces with NO active operator don't get sweeps. For
// SLA + snooze this is acceptable — both surface again the moment
// someone opens /team-inbox. If a host wants stricter cadence, they
// can still call any of those commands manually from cron themselves.

// ── Advanced Scaling (opt-in): cron-driven main sweeps ────────────────────────
// When the admin turns on Advanced Scaling (SystemSetting scaling_mode=cron_queue)
// AND adds a real OS cron line `* * * * * php artisan schedule:run`, the four
// TIME-CRITICAL sweeps run here on the cron clock — so they no longer depend on
// the single Node heartbeat (which stalls all tenants if Node is down). Each
// sweeper is single-flight lock-guarded, so firing from BOTH cron and the
// heartbeat is safe (whoever grabs the lock runs, the other skips). Gated on the
// toggle, so a DEFAULT install (scaling_mode=node) — even one that happens to run
// schedule:run — keeps today's behaviour and this closure does nothing.
//
// NOTE: the lighter sweeps (scheduled messages, referral, invoice, appointments,
// device health) and all real-time replies deliberately STAY on the heartbeat /
// inline path — only the heavy, time-sensitive four move here.
use Illuminate\Support\Facades\Schedule;
use App\Support\Scaling;

Schedule::call(function () {
    if (! Scaling::enabled()) return;      // default install → no-op
    Scaling::markCronRun();                // health: proves cron is alive
    foreach ([
        fn () => app(\App\Services\FlowDelayResumeSweeper::class)->sweep(),   // flow delays resume on time
        fn () => app(\App\Services\CampaignScheduleSweeper::class)->sweep(),  // scheduled campaigns launch + stall-rescue
        fn () => app(\App\Services\CampaignFollowupSweeper::class)->sweep(),  // no-reply follow-ups
        fn () => app(\App\Services\BroadcastSweeper::class)->sweep(),         // broadcast drain/retry
        fn () => app(\App\Services\Drip\DripRunner::class)->drain(200),       // drip sequences advance
    ] as $task) {
        try { $task(); } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[SCALING-CRON] sweep failed: ' . $e->getMessage());
        }
    }
})->everyMinute()->name('advanced-scaling-sweeps')->withoutOverlapping();
