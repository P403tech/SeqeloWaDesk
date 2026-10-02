<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mobile/API equivalent of EnsureTrialActive (#31).
 *
 * The WEB app blocks an expired-free-trial workspace from using paid features
 * (EnsureTrialActive on the web group). The mobile API had NO equivalent gate,
 * so an account blocked on the website could still use those features from the
 * phone. This runs after auth:sanctum + app.workspace and returns 402 JSON when
 * the selected workspace's free trial has elapsed.
 *
 * Recovery/billing endpoints stay open (path-based — mobile routes have no
 * consistent names) so the user can still upgrade, top up, switch workspace or
 * log out. Platform admins bypass. Only FREE plans past their trial are ever
 * "expired" (Workspace::trialExpired()), so paid plans are unaffected. Fails
 * OPEN — any error lets the request through so a gate bug can't brick the app.
 */
class EnsureTrialActiveApi
{
    /** Relative path globs that stay reachable while a trial is expired. */
    private const ALLOWED = [
        '*billing*', '*plan*', '*wallet*', '*credit*', '*checkout*', '*payment*',
        '*invoice*', '*subscription*', 'user', 'user/*', 'profile*',
        'workspaces', 'workspaces/*', 'logout', 'auth/*', 'notifications*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! is_file(storage_path('installed'))) {
            return $next($request);
        }

        try {
            $user = $request->user();
            if (! $user || $this->isAdmin($user)) {
                return $next($request);
            }

            // Keep recovery/billing endpoints open. Strip any api//app/ prefix so
            // the globs match the meaningful tail of the path.
            $tail = preg_replace('#^(api/)?(app/)?#', '', ltrim($request->path(), '/'));
            foreach (self::ALLOWED as $glob) {
                if (Str::is($glob, $tail)) {
                    return $next($request);
                }
            }

            $wsId = (int) ($request->header('X-Workspace-Id') ?: $user->current_workspace_id ?: 0);
            $ws = $wsId > 0 ? Workspace::find($wsId) : $user->currentWorkspace;
            if (! $ws || ! $ws->trialExpired()) {
                return $next($request);
            }

            return response()->json([
                'message'     => __('Your free trial has ended. Choose a plan to keep using your workspace.'),
                'trial'       => 'expired',
                'upgrade_url' => url('/account/plans'),
            ], 402);
        } catch (\Throwable $e) {
            return $next($request); // fail open
        }
    }

    /** Mirror EnsureTrialActive::isAdmin so both gates agree. */
    private function isAdmin($user): bool
    {
        try {
            if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) {
                return true;
            }
        } catch (\Throwable $e) {
        }
        return in_array($user->role ?? null, ['admin', 'A', 'super-admin', 'platform-admin'], true);
    }
}
