<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The frontend sends its page language (ar|en) as Accept-Language; answer in the same language.
 */
class SetLocaleFromHeader
{
    private const SUPPORTED = ['ar', 'en', 'fr'];

    /**
     * Switches the app language to the best supported match of the `Accept-Language` header.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->getPreferredLanguage(self::SUPPORTED);

        if ($locale && $request->hasHeader('Accept-Language')) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
