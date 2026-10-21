<?php

namespace App\Http\Requests\Auth;

class ForgotPasswordRequest extends IdentifierRequest
{
    /**
     * The email or phone number to send the reset code to comes in `target`.
     */
    protected function identifierField(): string
    {
        return 'target';
    }

    /**
     * Validates the method and the email or phone number to reset.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->identifierRules();
    }
}
