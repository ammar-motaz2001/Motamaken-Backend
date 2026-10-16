<?php

namespace App\Http\Middleware;

use App\OpenApi\ExampleRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only switched on with DOCS_RECORD=1, while the test suite runs: feeds every API call to the Swagger example recorder.
 */
class RecordApiExamples
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        ExampleRecorder::record($request, $response);

        return $response;
    }
}
