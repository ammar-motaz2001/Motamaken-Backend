<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Maintenance mode switched on from the admin panel: every API call answers 503 `maintenance`, except signing in, the
 * public site settings, payment webhooks and anything the staff do.
 */
class CheckMaintenance
{
    private const OPEN = ['api/health', 'api/auth/*', 'api/site-settings', 'api/webhooks/*', 'api/payments/*'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! Setting::get('maintenance.enabled') || $request->is(...self::OPEN) || $request->user('sanctum')?->is_admin) {
            return $next($request);
        }

        $locale = app()->getLocale() === 'ar' ? 'ar' : 'en';

        return response()->json([
            'message' => Setting::get("maintenance.message_{$locale}"),
            'code' => 'maintenance',
            'endsAt' => Setting::get('maintenance.ends_at'),
        ], 503);
    }
}
