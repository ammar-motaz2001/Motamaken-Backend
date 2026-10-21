<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `admin.can:<permission>`: the staff member's role must include the permission (any of several, separated by |).
 */
class AdminCan
{
    /**
     * Lets the request through when the admin has any one of the `|`-separated permissions, otherwise answers 403.
     */
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        foreach (explode('|', $permissions) as $permission) {
            if ($request->user()?->canAdmin($permission)) {
                return $next($request);
            }
        }

        return response()->json(['message' => __('admin.forbidden'), 'code' => 'admin_forbidden'], 403);
    }
}
