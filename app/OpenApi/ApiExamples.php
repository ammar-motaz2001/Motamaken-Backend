<?php

namespace App\OpenApi;

/**
 * Named request/response examples for the OpenAPI spec, shown in Swagger UI's example dropdowns.
 *
 * Scramble generates the schemas from the code; this only adds realistic payloads on top. Keys are
 * "METHOD /path" as they appear in the spec (without the /api prefix). Values mirror what the API
 * really returns — update them when a response shape changes (ApiDocsTest catches stale paths).
 */
class ApiExamples
{
    private const TOKEN = '3|T7iRTeIW2yhw45MK2oVq8cJ0nUe1XbZyL5sPdA9f7c2e41b8';

    private const RESET_TOKEN = 'fwO56FcynYZMq1rVb7TgHkL2xN9pEaWd3sJu8QiC0oRmX4tBzK6yGhD5lPnVcSe1';

    /**
     * Add the examples to a generated spec.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    public static function apply(array $spec): array
    {
        // Hand-written examples first; recorded ones (ExampleRecorder) fill in every endpoint that has none.
        $all = [...ExampleRecorder::load(), ...self::operations()];

        foreach ($all as $key => $examples) {
            [$method, $path] = explode(' ', $key, 2);

            if (! isset($spec['paths'][$path][strtolower($method)])) {
                continue;
            }

            $operation = &$spec['paths'][$path][strtolower($method)];

            if (isset($examples['request'], $operation['requestBody']['content'])) {
                $type = array_key_first($operation['requestBody']['content']);
                $operation['requestBody']['content'][$type]['examples'] = self::named($examples['request']);
            }

            // File-based examples (voice messages, attachments) can't be sent as plain JSON, so they are listed apart.
            if (isset($examples['requestMultipart'])) {
                $operation['requestBody']['content']['multipart/form-data']['examples'] = self::named($examples['requestMultipart']);
            }

            // Endpoints Scramble documents as multipart (they accept a file) can also take plain JSON:
            // same schema without the file field, under its own name.
            if (isset($examples['requestJson'])) {
                $multipart = $operation['requestBody']['content']['multipart/form-data'];
                $name = basename($multipart['schema']['$ref']);
                $schema = $spec['components']['schemas'][$name];
                unset($schema['properties']['file']);
                $jsonName = $examples['requestJsonSchema'] ?? "{$name}Json";
                $spec['components']['schemas'][$jsonName] = [...$schema, 'title' => $jsonName];
                $operation['requestBody']['content']['application/json'] = [
                    'schema' => ['$ref' => "#/components/schemas/{$jsonName}"],
                    'examples' => self::named($examples['requestJson']),
                ];
            }

            foreach ($examples['responses'] ?? [] as $status => $definition) {
                if ($definition === null) {
                    unset($operation['responses'][$status]);

                    continue;
                }

                [$description, $responseExamples] = $definition;
                $response = self::resolve($spec, $operation['responses'][$status] ?? []);
                $response['description'] = $description;

                if ($responseExamples) {
                    $content = &$response['content']['application/json'];
                    $content['schema'] ??= self::messageSchema();
                    $content['examples'] = self::named($responseExamples);

                    // Plain {message} errors can share a status with validation errors, so `errors` is optional.
                    if (in_array('errors', $content['schema']['required'] ?? [], true)) {
                        $content['schema']['required'] = ['message'];
                    }
                    unset($content);
                } else {
                    unset($response['content']);
                }

                $operation['responses'][$status] = $response;
            }

            ksort($operation['responses']);
            unset($operation);
        }

        return $spec;
    }

    /**
     * The hand-written examples for every documented endpoint, keyed by method and path.
     *
     * @return array<string, array{request?: array, requestJson?: array, requestMultipart?: array, responses?: array<int, array{0: string, 1: array}|null>}>
     */
    public static function operations(): array
    {
        return [...self::auth(), ...HomepageExamples::operations(), ...ProposalsExamples::operations(), ...MessagesExamples::operations()];
    }

    /**
     * Examples for the health check and the auth endpoints (sign-up, login, OTP, password, me, logout).
     *
     * @return array<string, array{request?: array, requestJson?: array, requestMultipart?: array, responses?: array<int, array{0: string, 1: array}|null>}>
     */
    private static function auth(): array
    {
        $emailUser = self::user();
        $phoneUser = self::user([
            'id' => 13,
            'firstName' => 'سارة',
            'lastName' => 'أحمد',
            'username' => 'سارة',
            'email' => null,
            'emailVerifiedAt' => null,
            'phone' => '+966501234567',
            'phoneVerifiedAt' => '2026-09-27T04:22:31.000000Z',
            'subscribed' => false,
        ]);

        $throttled = ['Too many attempts. Please try again in a minute.'];
        $unauthenticated = self::unauthenticated();

        return [
            'GET /health' => [
                'responses' => [
                    200 => ['Postgres and Redis are reachable', [
                        'Healthy' => [
                            'status' => 'ok',
                            'database' => 'PostgreSQL 16.15 on aarch64-unknown-linux-musl, compiled by gcc (Alpine 15.2.0) 15.2.0, 64-bit',
                            'redis' => 'ok',
                        ],
                    ]],
                ],
            ],

            'POST /auth/signup' => [
                'request' => [
                    'With email' => [
                        'method' => 'email',
                        'firstName' => 'Ammar',
                        'lastName' => 'Motaz',
                        'username' => 'Ammar',
                        'email' => 'ammar@example.com',
                        'password' => 'Secret#123',
                        'subscribe' => true,
                    ],
                    'With phone (WhatsApp)' => [
                        'method' => 'phone',
                        'firstName' => 'سارة',
                        'lastName' => 'أحمد',
                        'username' => 'سارة',
                        'phone' => '+966501234567',
                        'password' => 'Secret#123',
                        'subscribe' => false,
                    ],
                ],
                'responses' => [
                    200 => ['Account created, signup code sent', [
                        'Code sent by email' => ['target' => 'ammar@example.com'],
                        'Code sent by WhatsApp' => ['target' => '+966501234567'],
                    ]],
                    422 => ['Invalid input or identifier already registered', [
                        'Weak password' => self::validation('password', [
                            'The password field must contain at least one symbol.',
                            'The password field must contain at least one number.',
                        ]),
                        'Already registered' => ['message' => 'An account with this email already exists. Please log in.'],
                    ]],
                    429 => ['Rate limited', $throttled],
                ],
            ],

            'POST /auth/login' => [
                'request' => [
                    'With email' => ['method' => 'email', 'identifier' => 'ammar@example.com', 'password' => 'Secret#123'],
                    'With phone' => ['method' => 'phone', 'identifier' => '+966501234567', 'password' => 'Secret#123'],
                ],
                'responses' => [
                    200 => ['Logged in, or a login code was sent', [
                        'Code required (email)' => ['otpRequired' => true],
                        'Logged in (phone)' => ['otpRequired' => false, 'accessToken' => self::TOKEN, 'user' => $phoneUser],
                    ]],
                    422 => ['Wrong credentials or invalid input', [
                        'Wrong password' => ['message' => 'The email or password is incorrect.'],
                        'Invalid email' => self::validation('identifier', ['The email or phone field must be a valid email address.']),
                    ]],
                    429 => ['Rate limited', $throttled],
                ],
            ],

            'POST /auth/otp/verify' => [
                'request' => [
                    'Signup code' => ['purpose' => 'signup', 'method' => 'email', 'target' => 'ammar@example.com', 'code' => '4821'],
                    'Login code' => ['purpose' => 'login', 'method' => 'email', 'target' => 'ammar@example.com', 'code' => '6104'],
                    'Password reset code' => ['purpose' => 'reset', 'method' => 'phone', 'target' => '+966501234567', 'code' => '3909'],
                ],
                'responses' => [
                    200 => ['Code accepted; the body depends on purpose', [
                        'purpose=signup' => ['verified' => true],
                        'purpose=login' => ['accessToken' => self::TOKEN, 'user' => $emailUser],
                        'purpose=reset' => ['token' => self::RESET_TOKEN],
                    ]],
                    422 => ['Wrong or expired code', [
                        'Wrong code' => ['message' => 'The verification code is incorrect.'],
                        'Expired code' => ['message' => 'The verification code has expired. Please request a new one.'],
                        'Malformed code' => self::validation('code', ['The code field must be 4 digits.']),
                    ]],
                    429 => ['Too many wrong attempts, or rate limited', [
                        'Code locked' => ['message' => 'Too many incorrect attempts. Please request a new code.'],
                        ...$throttled,
                    ]],
                ],
            ],

            'POST /auth/otp/resend' => [
                'request' => [
                    'Resend signup code' => ['purpose' => 'signup', 'method' => 'email', 'target' => 'ammar@example.com'],
                ],
                'responses' => [
                    200 => ['New code sent (same answer when there was nothing to resend)', [
                        'Sent' => ['sent' => true],
                    ]],
                    422 => ['Invalid input', [
                        'Unknown purpose' => self::validation('purpose', ['The selected purpose is invalid.']),
                    ]],
                    429 => ['Cooldown not over, or rate limited', [
                        'Cooldown' => ['message' => 'Please wait 42 seconds before requesting a new code.'],
                        ...$throttled,
                    ]],
                ],
            ],

            'POST /auth/password/forgot' => [
                'request' => [
                    'By email' => ['method' => 'email', 'target' => 'ammar@example.com'],
                    'By phone (WhatsApp)' => ['method' => 'phone', 'target' => '+966501234567'],
                ],
                'responses' => [
                    200 => ['Reset code sent if the account exists', [
                        'Sent' => ['sent' => true],
                    ]],
                    422 => ['Invalid input', [
                        'Invalid phone' => self::validation('target', ['The email or phone field must be a valid phone number with its country code.']),
                    ]],
                    429 => ['Rate limited', $throttled],
                ],
            ],

            'POST /auth/password/reset' => [
                'request' => [
                    'New password' => ['token' => self::RESET_TOKEN, 'password' => 'NewSecret#456'],
                ],
                'responses' => [
                    200 => ['Password changed, all sessions logged out', [
                        'Changed' => ['success' => true],
                    ]],
                    422 => ['Invalid/used token or weak password', [
                        'Invalid token' => ['message' => 'This password reset link is invalid or has expired.'],
                        'Weak password' => self::validation('password', ['The password field must contain at least one uppercase and one lowercase letter.']),
                    ]],
                    429 => ['Rate limited', $throttled],
                ],
            ],

            'GET /auth/me' => [
                'responses' => [
                    200 => ['The logged-in user', [
                        'Email user' => ['data' => $emailUser],
                        'Phone user' => ['data' => $phoneUser],
                    ]],
                ] + $unauthenticated,
            ],

            'POST /auth/logout' => [
                'responses' => [
                    200 => ['Token revoked', [
                        'Logged out' => ['success' => true],
                    ]],
                ] + $unauthenticated,
            ],
        ];
    }

    /**
     * The 401 documented on every endpoint that needs a token.
     *
     * @return array<int, array{0: string, 1: array}>
     */
    public static function unauthenticated(): array
    {
        return [401 => ['Missing, invalid or expired access token', [
            'Unauthenticated' => ['message' => 'Unauthenticated.'],
        ]]];
    }

    /**
     * A sample user as the API returns it, with any fields replaced.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function user(array $overrides = []): array
    {
        return [
            'id' => 12,
            'firstName' => 'Ammar',
            'lastName' => 'Motaz',
            'fullName' => 'Ammar Motaz',
            'username' => 'Ammar',
            'avatarUrl' => null,
            'email' => 'ammar@example.com',
            'emailVerifiedAt' => '2026-09-27T04:20:11.000000Z',
            'phone' => null,
            'phoneVerifiedAt' => null,
            'points' => 50,
            'subscribed' => true,
            'locale' => null,
            'currency' => 'USD',
            'theme' => 'light',
            'createdAt' => '2026-09-27T04:20:07.000000Z',
            ...$overrides,
        ];
    }

    /**
     * A sample 422 body in Laravel's format for one field.
     *
     * @param  list<string>  $messages
     * @return array{message: string, errors: array<string, list<string>>}
     */
    public static function validation(string $field, array $messages): array
    {
        $more = count($messages) - 1;

        return [
            'message' => $messages[0].($more ? " (and {$more} more ".($more === 1 ? 'error' : 'errors').')' : ''),
            'errors' => [$field => $messages],
        ];
    }

    /**
     * Turn ['Name' => value] into OpenAPI named examples. A plain string entry is a {message} error.
     *
     * @param  array<int|string, mixed>  $examples
     * @return array<string, array{summary: string, value: mixed}>
     */
    private static function named(array $examples): array
    {
        $named = [];

        foreach ($examples as $name => $value) {
            if (is_int($name)) {
                [$name, $value] = ['Rate limited', ['message' => $value]];
            }

            $named[str($name)->slug()->toString()] = ['summary' => $name, 'value' => $value];
        }

        return $named;
    }

    /**
     * Inline a `$ref` response (e.g. the shared ValidationException) so examples can be attached per operation.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private static function resolve(array $spec, array $response): array
    {
        if (isset($response['$ref'])) {
            return $spec['components']['responses'][basename($response['$ref'])] ?? [];
        }

        return $response;
    }

    /**
     * The schema of a plain `{message}` error body.
     *
     * @return array<string, mixed>
     */
    private static function messageSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['message' => ['type' => 'string', 'description' => 'Human-readable error, localized by Accept-Language.']],
            'required' => ['message'],
        ];
    }
}
