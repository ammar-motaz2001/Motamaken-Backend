<?php

use App\Http\Controllers\ApiDocsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// API docs: the spec Scramble generates from the code plus App\OpenApi\ApiExamples, and Swagger UI over it.
Route::middleware(config('scramble.middleware'))->group(function () {
    Route::get('docs/api.json', [ApiDocsController::class, 'spec'])->name('docs.spec');
    Route::get('swagger', [ApiDocsController::class, 'swagger'])->name('docs.swagger');
});
