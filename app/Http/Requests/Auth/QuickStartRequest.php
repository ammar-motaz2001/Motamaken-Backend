<?php

namespace App\Http\Requests\Auth;

use App\Rules\Recaptcha;

class QuickStartRequest extends IdentifierRequest
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
            /** The reCAPTCHA checkbox token. Required when the server has reCAPTCHA keys. */
            'recaptchaToken' => Recaptcha::rules(),
        ];
    }
}
