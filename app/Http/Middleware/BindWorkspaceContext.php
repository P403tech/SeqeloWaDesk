<?php

namespace App\Http\Middleware;

use App\Support\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pins the signed-in user's current workspace for the rest of the request.
 * Admin pages stay unscoped so platform lists can see every tenant.
 */
class BindWorkspaceContext
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('admin', 'admin/*', 'install', 'install/*')) {
            return $next($request);
        }

        $user = $request->user();
        $wsId = (int) ($user->current_workspace_id ?? 0);
        if (! $user || $wsId <= 0) {
            return $next($request);
        }

        WorkspaceContext::set(
            $wsId,
            (int) $user->id,
            (bool) $request->session()->get('impersonation_session_id')
        );

        try {
            return $next($request);
        } finally {
            WorkspaceContext::clear();
        }
    }
}
