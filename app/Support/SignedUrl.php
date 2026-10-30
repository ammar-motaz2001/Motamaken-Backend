<?php

namespace App\Support;

use Illuminate\Support\Facades\URL;

/**
 * Signed file links whose signature covers only the path and query, not the host or scheme: the same link verifies
 * whether the API was reached through Cloudflare (https), Caddy (http) or the website's server. The routes check them
 * with the `signed:relative` middleware.
 */
class SignedUrl
{
    public static function temporary(string $route, \DateTimeInterface $expires, array $parameters = []): string
    {
        return URL::to(URL::temporarySignedRoute($route, $expires, $parameters, absolute: false));
    }
}
