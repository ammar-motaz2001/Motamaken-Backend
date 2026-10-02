<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DepositRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** In the chosen currency, up to 2 decimals. */
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'currency' => ['required', Rule::in(array_column(config('marketplace.currencies'), 'code'))],
            /** `card`, `bank_transfer`, `apple_pay` or `mada`. */
            'method' => ['required', Rule::in(config('payments.deposit_methods'))],
        ];
    }
}
