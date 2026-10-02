<?php

namespace App\Http\Requests\Auth;

class LoginRequest extends IdentifierRequest
{
    protected function identifierField(): string
    {
        return 'identifier';
    }

    /**
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
