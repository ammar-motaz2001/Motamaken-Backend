<?php

namespace App\Http\Requests\Auth;

use App\Enums\OtpPurpose;
use Illuminate\Validation\Rule;

class ResendOtpRequest extends IdentifierRequest
{
    protected function identifierField(): string
    {
        return 'target';
    }

    public function purpose(): OtpPurpose
    {
        return OtpPurpose::from($this->input('purpose'));
    }

    /**
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
