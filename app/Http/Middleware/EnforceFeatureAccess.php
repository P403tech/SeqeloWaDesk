<?php

namespace App\Http\Middleware;

use App\Support\FeatureRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hard gate for the admin Feature Toggles (/admin/settings/features).
 *
 * Hiding a feature from the nav is not enough — a user could still open its URL
 * directly. This middleware makes a disabled feature genuinely unreachable: if
 * the request path belongs to a feature an admin switched OFF, it 404s (the
 * page effectively no longer exists).
 *
 * It is a NO-OP for anything that isn't a matched, disabled feature path, so it
 * never touches admin, API, auth, billing or unrelated pages. Default is ON, so
 * nothing is blocked until an admin turns a feature off. The dashboard is never
 * blocked (marked block=false in the registry) so a user can never be stranded.
 */
class EnforceFeatureAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        // Feature routes are all authenticated user pages. Guests are handled by
        // the auth middleware; admin/api paths are never in the registry.
        if (! $request->user()) {
            return $next($request);
        }

        $path = '/' . ltrim($request->path(), '/');
        if (str_starts_with($path, '/admin') || str_starts_with($path, '/api')) {
            return $next($request);
        }

        $feature = FeatureRegistry::matchPath($path);
        if ($feature !== null && ! FeatureRegistry::visible((string) $feature['key'])) {
            abort(404);
        }

        return $next($request);
    }
}
