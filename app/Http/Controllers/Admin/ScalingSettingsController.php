<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Support\Scaling;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

/**
 * /admin/settings/scaling — Advanced Scaling (opt-in).
 *
 * OFF (default 'node'): the Node heartbeat drives every sweep and heavy work runs
 * inline — zero-config, unchanged. ON ('cron_queue'): the admin has set up an OS
 * cron + Redis + a queue worker; the main sweeps run on cron and campaign/broadcast
 * sends dispatch to Redis. This page flips the toggle and PROVES the pieces are
 * alive (Redis reachable, worker seen, cron ran) so it can never be enabled into a
 * broken state where jobs pile up unprocessed.
 */
class ScalingSettingsController extends Controller
{
    public function index()
    {
        return view('admin.scaling.index', $this->health());
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'scaling_mode'     => 'required|in:node,cron_queue',
            'queue_connection' => 'nullable|in:redis',
        ]);

        // Guard: enabling cron_queue requires Redis to actually be reachable NOW,
        // else queued sends would vanish into an unreachable connection. (Cron and
        // the worker can only start AFTER enabling, so those aren't hard-blocked —
        // the health strip warns until they report in.)
        if ($data['scaling_mode'] === 'cron_queue' && ! $this->redisReachable()) {
            return back()->with('error', __('Redis is not reachable yet. Install Redis and set REDIS_* in .env, then enable Advanced Scaling.'));
        }

        SystemSetting::set('scaling_mode', $data['scaling_mode'], 'string', 'Advanced scaling: node | cron_queue');
        SystemSetting::set('queue_connection', $data['queue_connection'] ?: 'redis', 'string', 'Queue connection when scaling on');

        try {
            \App\Services\Inbox\AuditLogger::platform(
                'settings.scaling.save', auth()->id(), null, 'setting', null,
                ['scaling_mode' => $data['scaling_mode']]
            );
        } catch (\Throwable $e) { /* audit is best-effort */ }

        return back()->with('success', __('Advanced scaling settings saved.'));
    }

    /** JSON health strip — polled by the page every few seconds. */
    public function healthJson()
    {
        return response()->json($this->health()['health']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function health(): array
    {
        $redis  = $this->redisReachable();
        $worker = Scaling::workerHealthy();
        $cronTs = Scaling::cronLastRun();

        return [
            'enabled'          => Scaling::enabled(),
            'scaling_mode'     => (string) SystemSetting::get('scaling_mode', 'node'),
            'queue_connection' => (string) SystemSetting::get('queue_connection', 'redis'),
            'webhookUrl'       => url('/'),
            'health' => [
                'redis_reachable' => $redis,
                'worker_alive'    => $worker,
                'cron_alive'      => Scaling::cronHealthy(),
                'cron_last_run'   => $cronTs ? now()->timestamp - $cronTs : null, // seconds ago
                'checked_at'      => now()->toDateTimeString(),
            ],
        ];
    }

    private function redisReachable(): bool
    {
        try {
            // Works with predis OR phpredis; ping throws/returns false if down or
            // the client isn't installed — either way we report "not reachable".
            $res = Redis::connection()->ping();
            return $res === true || $res === 'PONG' || $res === '+PONG' || (is_string($res) && stripos($res, 'PONG') !== false);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
