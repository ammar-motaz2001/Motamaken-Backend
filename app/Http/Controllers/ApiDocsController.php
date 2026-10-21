<?php

namespace App\Http\Controllers;

use App\OpenApi\ApiExamples;
use Dedoc\Scramble\CacheableGenerator;
use Dedoc\Scramble\Scramble;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * Serves the Scramble-generated OpenAPI spec with our named examples, and Swagger UI on top of it.
 */
class ApiDocsController extends Controller
{
    /**
     * OpenAPI spec.
     *
     * The generated OpenAPI document as JSON, with our recorded request and response examples merged in.
     */
    public function spec(CacheableGenerator $generator): JsonResponse
    {
        return response()->json(
            ApiExamples::apply($generator(Scramble::getGeneratorConfig('default'))),
            options: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Swagger UI.
     *
     * An interactive page to browse and try the API, reading the spec above.
     */
    public function swagger(): View
    {
        return view('swagger');
    }
}
