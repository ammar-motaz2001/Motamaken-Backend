<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WalletHistoryRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'currency' => ['nullable', Rule::in(array_column(config('marketplace.currencies'), 'code'))],
            /** `deposit`, `withdrawal_request`, `withdrawal_cancelled`, `withdrawal_rejected`, `escrow_fund`, `escrow_release`... */
            'type' => ['nullable', 'string', 'max:40'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
