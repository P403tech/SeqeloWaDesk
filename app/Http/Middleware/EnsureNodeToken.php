<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared secret for Node → Laravel callbacks. An empty token is never a pass.
 */
class EnsureNodeToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = node_token();
        $given = (string) $request->header('X-Node-Token', '');

        if ($expected === '' || ! hash_equals($expected, $given)) {
            return response()->json(['ok' => false, 'message' => 'forbidden'], 403);
        }

        return $next($request);
    }
}
