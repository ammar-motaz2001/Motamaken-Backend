<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin panel: administrators only, and with two-factor sign-in when the "staff must use two-factor" policy is on.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user?->is_admin, 403, __('auth_flow.admins_only'));

        if (Setting::get('admin.require_2fa', false) && ! $user->hasTwoFactor()) {
            return response()->json(['message' => __('admin.two_factor_required'), 'code' => 'admin_2fa_required'], 403);
        }

        return $next($request);
    }
}
