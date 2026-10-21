<?php

namespace App\OpenApi;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

/**
 * Real request/response examples for Swagger, captured while the test suite runs.
 *
 * Run `DOCS_RECORD=1 php artisan test` and every API call the tests make is collected: up to two request bodies per
 * endpoint (from successful calls) and up to three different responses per status, errors included. The result is
 * written to recorded-examples.json, which ApiExamples uses for every endpoint that has no hand-written examples.
 */
class ExampleRecorder
{
    public const FILE = __DIR__.'/recorded-examples.json';

    private const MAX_REQUESTS = 2;

    private const MAX_PER_STATUS = 3;

    private const MAX_LIST_ITEMS = 3;

    /** @var array<string, array{request: array<string, mixed>, responses: array<int, array<string, mixed>>}> */
    private static array $collected = [];

    private static bool $registered = false;

    /**
     * Collect one API call made by a test: its request body and response, if not already seen enough times.
     */
    public static function record(Request $request, Response $response): void
    {
        $uri = $request->route()?->uri();

        if (! $uri || ! str_starts_with($uri, 'api/') || ! $response instanceof JsonResponse) {
            return;
        }

        $key = strtoupper($request->method()).' '.substr($uri, 3);
        $entry = self::$collected[$key] ??= ['request' => [], 'responses' => []];
        $status = $response->getStatusCode();
        $body = self::trim($response->getData(true));

        // Debug details (tests run with APP_DEBUG on) are never part of the documented answer.
        if (is_array($body)) {
            unset($body['exception'], $body['file'], $body['line'], $body['trace']);
        }

        $requestBody = self::requestBody($request);

        if ($status < 300 && $requestBody && count($entry['request']) < self::MAX_REQUESTS) {
            $signature = implode(',', array_keys(Arr::dot($requestBody)));

            if (! isset($entry['request'][$signature])) {
                $entry['request'][$signature] = $requestBody;
            }
        }

        $seen = $entry['responses'][$status] ?? [];
        $signature = self::responseSignature($status, $body);

        if (count($seen) < self::MAX_PER_STATUS && ! isset($seen[$signature])) {
            $entry['responses'][$status][$signature] = $body;
        }

        self::$collected[$key] = $entry;

        if (! self::$registered) {
            self::$registered = true;
            register_shutdown_function([self::class, 'write']);
        }
    }

    /**
     * Save what was collected, as named examples in the format ApiExamples::apply() reads.
     */
    public static function write(): void
    {
        $out = [];
        ksort(self::$collected);

        foreach (self::$collected as $key => $entry) {
            $requests = [];

            foreach (array_values($entry['request']) as $i => $body) {
                $requests[$i === 0 ? 'Example' : 'Example '.($i + 1)] = $body;
            }

            $responses = [];
            ksort($entry['responses']);

            foreach ($entry['responses'] as $status => $bodies) {
                $named = [];

                foreach (array_values($bodies) as $i => $body) {
                    $name = self::exampleName((int) $status, $body);
                    $named[isset($named[$name]) ? "{$name} ".($i + 1) : $name] = $body;
                }

                $responses[$status] = [self::describe((int) $status), $named];
            }

            $out[$key] = array_filter(['request' => $requests ?: null, 'responses' => $responses]);
        }

        file_put_contents(self::FILE, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    /**
     * What was recorded last time, in the ApiExamples::operations() format.
     *
     * @return array<string, array{request?: array<string, mixed>, responses?: array<int, array{0: string, 1: array<string, mixed>}>}>
     */
    public static function load(): array
    {
        return is_file(self::FILE) ? (json_decode((string) file_get_contents(self::FILE), true) ?: []) : [];
    }

    /**
     * The request body to show, with uploaded files replaced by their names; null when empty.
     *
     * @return array<string, mixed>|null
     */
    private static function requestBody(Request $request): ?array
    {
        $body = $request->isJson() ? $request->json()->all() : $request->request->all();

        foreach ($request->allFiles() as $field => $file) {
            $body[$field] = $file instanceof UploadedFile ? '(file: '.$file->getClientOriginalName().')' : '(files)';
        }

        return $body ?: null;
    }

    /**
     * Two responses are "the same example" when they share the status and, for errors, the code or the failing fields.
     *
     * @param  mixed  $body
     */
    private static function responseSignature(int $status, $body): string
    {
        if ($status < 400 || ! is_array($body)) {
            return 'ok';
        }

        return $body['code'] ?? (isset($body['errors']) ? implode(',', array_keys($body['errors'])) : ($body['message'] ?? 'error'));
    }

    /**
     * The example's name in Swagger, from its status and error code.
     *
     * @param  mixed  $body
     */
    private static function exampleName(int $status, $body): string
    {
        return match (true) {
            $status < 300 => 'Example',
            $status === 401 => 'Unauthenticated',
            $status === 403 => is_array($body) && isset($body['code']) ? ucfirst(str_replace('_', ' ', $body['code'])) : 'Forbidden',
            $status === 404 => 'Not found',
            $status === 429 => 'Rate limited',
            is_array($body) && isset($body['code']) => ucfirst(str_replace('_', ' ', $body['code'])),
            is_array($body) && isset($body['errors']) => 'Invalid '.implode(', ', array_slice(array_keys($body['errors']), 0, 3)),
            default => 'Error',
        };
    }

    /**
     * The response description Swagger shows for a status code.
     */
    private static function describe(int $status): string
    {
        return match ($status) {
            200 => 'Success',
            201 => 'Created',
            401 => 'Missing, invalid or expired access token',
            403 => 'Not allowed for this user',
            404 => 'Not found',
            422 => 'Invalid input, or refused by a rule (see `code`)',
            429 => 'Too many requests',
            default => 'Response',
        };
    }

    /**
     * Keep examples short: long lists are cut to their first few items.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private static function trim($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $value = array_slice($value, 0, self::MAX_LIST_ITEMS);
        }

        return array_map([self::class, 'trim'], $value);
    }
}
