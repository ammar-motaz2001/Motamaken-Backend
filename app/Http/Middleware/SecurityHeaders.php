<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser hardening for every response. API responses are JSON or files, never pages, so nothing may run, frame or
 * sniff them; the Swagger page keeps its scripts but gets the same framing, sniffing and transport rules.
 */
class SecurityHeaders
{
    /**
     * Adds the security headers; the strict content policy goes on everything except the Swagger UI page.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'cross-origin');
        if (! $request->routeIs('docs.swagger')) {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; sandbox");
        }
        $response->headers->remove('X-Powered-By');

        if (app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');
        }

        return $response;
    }
}
