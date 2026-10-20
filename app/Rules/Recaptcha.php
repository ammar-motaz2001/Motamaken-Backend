<?php

namespace App\Rules;

use App\Models\IntegrationLog;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Checks a reCAPTCHA v2 token with Google. Switched off (always passes) when RECAPTCHA_SECRET_KEY is empty, so local
 * setups and tests work without keys. Use it with `required_with` via {@see self::rules()}.
 */
class Recaptcha implements ValidationRule
{
    public static function enabled(): bool
    {
        return filled(config('services.recaptcha.secret_key'));
    }

    /**
     * Rules for the `recaptchaToken` field: required while reCAPTCHA is on, ignored otherwise.
     *
     * @return list<mixed>
     */
    public static function rules(): array
    {
        return self::enabled() ? ['required', 'string', 'max:4000', new self] : ['nullable'];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $result = Http::asForm()->timeout(5)->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => config('services.recaptcha.secret_key'),
                'response' => $value,
                'remoteip' => request()->ip(),
            ])->json();
        } catch (Throwable $e) {
            Log::warning("reCAPTCHA check failed: {$e->getMessage()}");
            IntegrationLog::record('recaptcha', "reCAPTCHA unreachable: {$e->getMessage()}");
            $result = null;
        }

        // v3 answers carry a score; v2 answers (no score) only need success.
        $score = $result['score'] ?? null;

        if (! ($result['success'] ?? false) || ($score !== null && (float) $score < (float) config('services.recaptcha.min_score'))) {
            if ($score !== null) {
                Log::info('reCAPTCHA refused a low score', ['score' => $score, 'action' => $result['action'] ?? null]);
            }
            $fail(__('auth_flow.recaptcha_failed'));
        }
    }
}
