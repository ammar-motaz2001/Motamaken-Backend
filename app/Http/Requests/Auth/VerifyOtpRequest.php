<?php

namespace App\Http\Requests\Auth;

class VerifyOtpRequest extends ResendOtpRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'code' => ['required', 'string', 'digits:'.config('auth_flow.otp.length')],
        ];
    }
}
