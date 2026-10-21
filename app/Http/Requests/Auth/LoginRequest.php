<?php

namespace App\Http\Requests\Auth;

class LoginRequest extends IdentifierRequest
{
    /**
     * The email or phone number comes in `identifier`.
     */
    protected function identifierField(): string
    {
        return 'identifier';
    }

    /**
     * Validates the email or phone number and the password.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->identifierRules(),
            'password' => ['required', 'string', 'max:255'],
        ];
    }
}
