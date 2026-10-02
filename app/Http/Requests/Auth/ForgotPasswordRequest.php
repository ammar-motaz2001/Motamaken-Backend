<?php

namespace App\Http\Requests\Auth;

class ForgotPasswordRequest extends IdentifierRequest
{
    protected function identifierField(): string
    {
        return 'target';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->identifierRules();
    }
}
