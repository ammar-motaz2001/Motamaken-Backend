<?php

namespace App\Http\Requests\Auth;

use App\Enums\OtpPurpose;
use Illuminate\Validation\Rule;

class ResendOtpRequest extends IdentifierRequest
{
    /**
     * The email or phone number the code went to comes in `target`.
     */
    protected function identifierField(): string
    {
        return 'target';
    }

    /**
     * What the code to resend is for: signup, login, reset or quick sign-in.
     */
    public function purpose(): OtpPurpose
    {
        return OtpPurpose::from($this->input('purpose'));
    }

    /**
     * Validates the identifier and what the code is for.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->identifierRules(),
            /** `signup`, `login`, `reset` or `quick`. */
            'purpose' => ['required', Rule::enum(OtpPurpose::class)->only([OtpPurpose::Signup, OtpPurpose::Login, OtpPurpose::Reset, OtpPurpose::Quick])],
        ];
    }
}
