<?php

use App\Http\Middleware\AdminCan;
use App\Http\Middleware\BlockBlacklistedIp;
use App\Http\Middleware\CheckMaintenance;
use App\Http\Middleware\RecordApiExamples;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocaleFromHeader;
use App\Http\Middleware\TrustFrontendProxy;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Render's (or any) load balancer: trust X-Forwarded-* so URLs and signed links use https.
        $middleware->trustProxies(at: '*');

        // Before the rate limiters run, so they count the real visitor and not the website's server.
        $middleware->prepend(TrustFrontendProxy::class);

        $middleware->api(append: [SetLocaleFromHeader::class, CheckMaintenance::class, BlockBlacklistedIp::class]);
        $middleware->alias(['admin.can' => AdminCan::class]);
        // The API has no login page: a guest gets a JSON 401, never a redirect (which crashed without Accept: json).
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
        // Global, so 404s and other errors get them too (a strict policy everywhere but the Swagger page).
        $middleware->append(SecurityHeaders::class);

        // DOCS_RECORD=1 php artisan test: capture real requests and responses as Swagger examples.
        if (env('DOCS_RECORD')) {
            $middleware->api(prepend: [RecordApiExamples::class]);
        }

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
