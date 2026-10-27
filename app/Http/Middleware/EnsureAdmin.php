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
    /**
     * Lets only admins through, and asks them to turn on two-factor first when the site requires it.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        // Non-staff get the same "not found" as any unknown address: the panel's routes are not confirmed to exist.
        abort_unless($user?->is_admin, 404, __('errors.not_found'));

        if (Setting::get('admin.require_2fa', false) && ! $user->hasTwoFactor()) {
            return response()->json(['message' => __('admin.two_factor_required'), 'code' => 'admin_2fa_required'], 403);
        }

        return $next($request);
    }
}
