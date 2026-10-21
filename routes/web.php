<?php

use App\Http\Controllers\ApiDocsController;
use Illuminate\Support\Facades\Route;

// The API host's root: a plain pointer, without the session and CSRF cookies the web group would set.
Route::get('/', fn () => response()->json(['name' => config('app.name').' API', 'health' => url('/api/health')]))
    ->withoutMiddleware('web');

// API docs: the spec Scramble generates from the code plus App\OpenApi\ApiExamples, and Swagger UI over it.
Route::middleware(config('scramble.middleware'))->group(function () {
    Route::get('docs/api.json', [ApiDocsController::class, 'spec'])->name('docs.spec');
    Route::get('swagger', [ApiDocsController::class, 'swagger'])->name('docs.swagger');
});
