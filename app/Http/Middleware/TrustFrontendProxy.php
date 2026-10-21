<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The website calls the API from its own server, so every request would look like it came from that server: one IP for
 * everyone, which breaks rate limits and the visit filters. The website's proxy therefore sends the visitor's address,
 * browser and country, signed with a secret only the two servers know. Without the right secret nothing is changed, so
 * the headers cannot be forged by calling the API directly.
 */
class TrustFrontendProxy
{
    /**
     * Uses the visitor IP sent by our frontend server when the request carries the shared proxy secret.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.frontend_proxy.secret');
        $given = (string) $request->headers->get('X-Proxy-Secret');

        if ($secret !== '' && hash_equals($secret, $given)) {
            $ip = (string) $request->headers->get('X-Client-IP');

            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                // The visitor's address becomes the request's own, and the proxy chain is dropped so nothing overrides it.
                $request->server->set('REMOTE_ADDR', $ip);
                $request->headers->remove('X-Forwarded-For');
            }

            if ($agent = $request->headers->get('X-Client-User-Agent')) {
                $request->headers->set('User-Agent', $agent);
            }

            if ($country = $request->headers->get('X-Client-Country')) {
                $request->headers->set('CF-IPCountry', $country);
            }
        }

        $request->headers->remove('X-Proxy-Secret');

        return $next($request);
    }
}
