<?php

namespace App\Services\Threads;

use App\Models\ThreadsAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Keeps Threads long-lived tokens (~60d) fresh. Fires INLINE, cache-gated,
 * from the Threads pages — same pattern as Instagram's TokenRefreshSweeper. A
 * token is refreshed once it enters the runway window; a lapsed one is retired.
 */
class ThreadsTokenRefreshSweeper
{
    private const RUNWAY_DAYS = 10;   // refresh when <= this many days remain

    public function run(): void
    {
        if (! Cache::add('threads_token_refresh_lock', 1, 3600)) {
            return;
        }

        ThreadsAccount::query()
            ->where('status', 'connected')
            ->whereNotNull('access_token')
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', now()->addDays(self::RUNWAY_DAYS))
            ->orderBy('token_expires_at')
            ->limit(25)
            ->get()
            ->each(fn (ThreadsAccount $a) => $this->refreshOne($a));
    }

    public function refreshOne(ThreadsAccount $account): void
    {
        // Already expired → retire (can't be refreshed).
        if ($account->token_expires_at && $account->token_expires_at->isPast()) {
            $account->update(['status' => 'expired', 'last_error' => 'Threads token expired — reconnect']);
            return;
        }

        try {
            $res = ThreadsService::refreshLongLived((string) $account->access_token);
            if ($res) {
                $account->update([
                    'access_token'     => $res['access_token'],
                    'token_expires_at' => now()->addSeconds((int) $res['expires_in']),
                    'status'           => 'connected',
                    'last_error'       => null,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[THREADS] token refresh threw: ' . $e->getMessage(), ['account' => $account->id]);
        }
    }
}
