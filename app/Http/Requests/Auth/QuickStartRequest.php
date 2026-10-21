<?php

namespace App\Http\Requests\Auth;

use App\Rules\Recaptcha;

class QuickStartRequest extends IdentifierRequest
{
    /**
     * The identifier comes in `phone` or `email`, following the chosen method.
     */
    protected function identifierField(): string
    {
        return $this->input('method') === 'phone' ? 'phone' : 'email';
    }

    /**
     * Validates the email or phone number to send a sign-in code to, and the reCAPTCHA token.
     *
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
