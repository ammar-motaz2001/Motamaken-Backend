<?php

use App\Http\Controllers\ApiDocsController;
use App\Http\Controllers\Auth\OAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Browser redirects for social login. They sit in the web group (sessions, for OAuth state and X's PKCE)
// but keep the /api prefix so the frontend's `${NEXT_PUBLIC_API_URL}/auth/oauth/{provider}` links resolve.
Route::prefix('api/auth/oauth/{provider}')
    ->whereIn('provider', config('auth_flow.oauth_providers'))
    ->middleware('throttle:auth')
    ->group(function () {
        Route::get('/', [OAuthController::class, 'redirect']);
        Route::match(['get', 'post'], 'callback', [OAuthController::class, 'callback']);
    });

// API docs: the spec Scramble generates from the code plus App\OpenApi\ApiExamples, and Swagger UI over it.
Route::middleware(config('scramble.middleware'))->group(function () {
    Route::get('docs/api.json', [ApiDocsController::class, 'spec'])->name('docs.spec');
    Route::get('swagger', [ApiDocsController::class, 'swagger'])->name('docs.swagger');
});
