<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses HTML and script markup in anything users type (names, titles, descriptions, messages, reviews...), so text
 * like `<img src=x onerror=alert(0)>` or `<script>` is never stored. Answers a normal 422 naming the field.
 * Secrets are not checked (a password may contain "<"); markdown-only admin page bodies are allowed.
 */
class RejectMarkup
{
    /** Fields whose content is never shown as text (passwords, tokens) or is admin-written markdown. */
    private const SKIP = ['password', 'password_confirmation', 'currentPassword', 'token', 'recaptchaToken', 'code', 'secret', 'mailPassword', 'bodyEn', 'bodyAr', 'body_en', 'body_ar'];

    /** An HTML tag, a script URL or an inline event handler. */
    private const MARKUP = '/<\s*\/?\s*[a-z!][^>]*>|<\s*[a-z]|javascript\s*:|vbscript\s*:|data\s*:\s*text\/html|\bon[a-z]+\s*=/i';

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            $errors = [];
            $this->scan($request->except(self::SKIP), '', $errors);
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }
        }

        return $next($request);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $errors
     */
    private function scan(array $data, string $prefix, array &$errors): void
    {
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            if (is_array($value)) {
                $this->scan($value, $path, $errors);
            } elseif (is_string($value) && ! in_array((string) $key, self::SKIP, true) && preg_match(self::MARKUP, $value)) {
                $errors[$path] = __('validation.no_markup');
            }
        }
    }
}
