<?php

namespace App\Http\Requests\Auth;

use App\Enums\AuthMethod;
use App\Rules\Recaptcha;
use Illuminate\Validation\Rule;

class SignupRequest extends IdentifierRequest
{
    /**
     * The identifier comes in `phone` or `email`, following the chosen method.
     */
    protected function identifierField(): string
    {
        return $this->input('method') === 'phone' ? 'phone' : 'email';
    }

    /**
     * Validates a new account: email or phone, names, username, a strong password and the optional extras.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'method' => ['required', Rule::enum(AuthMethod::class)],
            /** Required when method is `email`. */
            'email' => ['required_if:method,email', 'nullable', 'string', 'email', 'max:255'],
            /** Required when method is `phone`. International format, e.g. `+966501234567`. */
            'phone' => ['required_if:method,phone', 'nullable', 'string', 'phone:INTERNATIONAL'],
            'firstName' => ['required', 'string', 'max:50', 'regex:/^[\p{L} ]+$/u'],
            'lastName' => ['required', 'string', 'max:50', 'regex:/^[\p{L} ]+$/u'],
            'username' => ['required', 'string', 'max:50', 'regex:/^[\p{L}\p{N}._]+( [\p{L}\p{N}._]+)*$/u'],
            'password' => static::passwordRules(),
            'subscribe' => ['sometimes', 'boolean'],
            /** The code from an invite link. An unknown code is simply ignored. */
            'referralCode' => ['nullable', 'string', 'max:12'],
            /** The reCAPTCHA checkbox token. Required when the server has reCAPTCHA keys. */
            'recaptchaToken' => Recaptcha::rules(),
        ];
    }
}
