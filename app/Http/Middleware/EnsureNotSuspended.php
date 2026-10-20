<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A suspended account keeps its tokens but every call is refused with a code the frontend can show a notice for. A timed
 * suspension lifts itself on the first call after it ends.
 */
class EnsureNotSuspended
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->suspended_at) {
            if ($user->isSuspended()) {
                return response()->json([
                    'message' => __('auth_flow.suspended'),
                    'code' => 'account_suspended',
                    'until' => $user->suspended_until?->toIso8601String(),
                ], 403);
            }

            $user->forceFill(['suspended_at' => null, 'suspended_until' => null, 'suspension_reason' => null])->save();
        }

        return $next($request);
    }
}
