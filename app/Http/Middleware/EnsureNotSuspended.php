<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A suspended account keeps its tokens but every call is refused with a code the frontend can show a notice for.
 */
class EnsureNotSuspended
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->suspended_at) {
            return response()->json(['message' => __('auth_flow.suspended'), 'code' => 'account_suspended'], 403);
        }

        return $next($request);
    }
}
