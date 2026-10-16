<?php

namespace App\Http\Requests\Auth;

use App\Enums\OtpPurpose;
use Illuminate\Validation\Rule;

class VerifyOtpRequest extends ResendOtpRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            /** `signup`, `login` or `reset` (quick codes go to `POST /auth/quick/verify`). */
            'purpose' => ['required', Rule::enum(OtpPurpose::class)->only([OtpPurpose::Signup, OtpPurpose::Login, OtpPurpose::Reset])],
            'code' => ['required', 'string', 'digits:'.config('auth_flow.otp.length')],
        ];
    }
}
