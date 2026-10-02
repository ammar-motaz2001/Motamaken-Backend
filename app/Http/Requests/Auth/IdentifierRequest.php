<?php

namespace App\Http\Requests\Auth;

use App\Enums\AuthMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Propaganistas\LaravelPhone\PhoneNumber;

/**
 * Base for auth requests that carry a "method" (email|phone) and an identifier in that format.
 */
abstract class IdentifierRequest extends FormRequest
{
    /**
     * The input field holding the email address or phone number.
     */
    abstract protected function identifierField(): string;

    public function authMethod(): AuthMethod
    {
        return AuthMethod::from($this->input('method'));
    }

    /**
     * The identifier normalized the way it is stored: lowercase email, or E.164 phone number.
     */
    public function identifier(): string
    {
        $value = trim((string) $this->input($this->identifierField()));

        return $this->authMethod() === AuthMethod::Email
            ? mb_strtolower($value)
            : (new PhoneNumber($value))->formatE164();
    }

    /**
     * @return array<string, mixed>
     */
    protected function identifierRules(): array
    {
        return [
            'method' => ['required', Rule::enum(AuthMethod::class)],
            /** An email address, or an international phone number (e.g. `+966501234567`) when method is `phone`. */
            $this->identifierField() => $this->input('method') === AuthMethod::Phone->value
                ? ['required', 'string', 'phone:INTERNATIONAL']
                : ['required', 'string', 'email', 'max:255'],
        ];
    }

    /**
     * The same checks as the frontend strength meter: 8+ chars, upper and lower case, a digit, a symbol.
     */
    public static function passwordRules(): array
    {
        return ['required', 'string', 'max:255', Password::min(8)->mixedCase()->numbers()->symbols()];
    }
}
