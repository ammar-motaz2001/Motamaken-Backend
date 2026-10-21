<?php

namespace App\Http\Middleware;

use App\Models\BlacklistEntry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Addresses on the blacklist may browse but not sign up, sign in or post anything.
 */
class BlockBlacklistedIp
{
    /**
     * Refuses changes from blacklisted IP addresses; reads and payment webhooks still pass.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe() && ! $request->is('api/webhooks/*') && BlacklistEntry::blocksIp($request->ip())) {
            return response()->json(['message' => __('admin.blocked'), 'code' => 'blocked'], 403);
        }

        return $next($request);
    }
}
