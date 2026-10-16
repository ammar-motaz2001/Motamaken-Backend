<?php

namespace App\Http\Requests\Auth;

class QuickVerifyRequest extends IdentifierRequest
{
    protected function identifierField(): string
    {
        return $this->input('method') === 'phone' ? 'phone' : 'email';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->identifierRules(),
            'code' => ['required', 'string', 'digits:'.config('auth_flow.otp.length')],
            /** New accounts only: the name to show. Letters, dots and single spaces. Defaults to the email's name part. */
            'name' => ['nullable', 'string', 'max:100', 'regex:/^[\p{L}.]+( [\p{L}.]+)*$/u'],
            /** New accounts only: the code from an invite link. */
            'referralCode' => ['nullable', 'string', 'max:12'],
        ];
    }
}
