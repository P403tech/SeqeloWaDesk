<?php

namespace App\Support;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Advanced-scaling switch (opt-in).
 *
 * DEFAULT (`scaling_mode` = 'node'): nothing changes — the Node heartbeat drives
 * every sweep and heavy work runs inline, exactly as a fresh install always has.
 * Zero-config; correct for shared hosting.
 *
 * OPT-IN (`scaling_mode` = 'cron_queue'): the admin has set up a real OS cron
 * (`* * * * * php artisan schedule:run`) and, for heavy sends, Redis + a queue
 * worker. Then the main time-critical sweeps fire on the cron clock (independent
 * of the single Node beat) and campaign/broadcast sends dispatch to Redis.
 *
 * Every sweeper is single-flight lock-guarded, so being triggered by BOTH cron
 * and the heartbeat is safe — whoever grabs the lock runs, the other skips.
 */
class Scaling
{
    /** True when the admin opted into cron + queue scaling. */
    public static function enabled(): bool
    {
        try {
            return (string) SystemSetting::get('scaling_mode', 'node') === 'cron_queue';
        } catch (\Throwable $e) {
            return false; // never let a settings hiccup change runtime behaviour
        }
    }

    /**
     * Queue connection for heavy background jobs. `redis` only when scaling is on
     * (the admin promises a worker is running); otherwise `sync` = run inline,
     * which is byte-for-byte the current behaviour.
     */
    public static function queueConnection(): string
    {
        if (! self::enabled()) return 'sync';
        return (string) SystemSetting::get('queue_connection', 'redis') ?: 'redis';
    }

    // ── Health probes (so the admin page can PROVE the pieces are alive) ──────

    /** Called at the top of every cron sweep so we can show "cron ran Ns ago". */
    public static function markCronRun(): void
    {
        try { Cache::put('scaling:cron_last_run', now()->timestamp, 3600); } catch (\Throwable $e) {}
    }

    public static function cronLastRun(): ?int
    {
        try { $v = Cache::get('scaling:cron_last_run'); return $v ? (int) $v : null; }
        catch (\Throwable $e) { return null; }
    }

    /** True when the OS cron fired schedule:run within the window (default 2 min). */
    public static function cronHealthy(int $withinSeconds = 120): bool
    {
        $t = self::cronLastRun();
        return $t !== null && (now()->timestamp - $t) <= $withinSeconds;
    }

    /** A queue worker writes this each time it processes a job (Phase 2). */
    public static function markWorkerAlive(): void
    {
        try { Cache::put('scaling:worker_last_seen', now()->timestamp, 3600); } catch (\Throwable $e) {}
    }

    public static function workerHealthy(int $withinSeconds = 90): bool
    {
        try {
            $t = Cache::get('scaling:worker_last_seen');
            return $t !== null && (now()->timestamp - (int) $t) <= $withinSeconds;
        } catch (\Throwable $e) { return false; }
    }
}
