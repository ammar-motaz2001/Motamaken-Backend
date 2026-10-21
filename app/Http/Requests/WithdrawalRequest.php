<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WithdrawalRequest extends FormRequest
{
    /**
     * Validates a withdrawal: amount, currency, method and the bank or PayPal destination.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $bank = $this->input('method') === 'bank_transfer';

        return [
            /** Without the fee, which is added on top. */
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'currency' => ['required', Rule::in(array_column(config('marketplace.currencies'), 'code'))],
            /** `bank_transfer` or `paypal`. */
            'method' => ['required', Rule::in(config('payments.withdrawal_methods'))],
            /** Where to send the money. */
            'destination' => ['required', 'array'],
            /** Bank transfer: the account holder's name. */
            'destination.accountName' => [Rule::requiredIf($bank), 'string', 'max:100'],
            /** Bank transfer: IBAN. */
            'destination.iban' => [Rule::requiredIf($bank), 'string', 'min:15', 'max:34', 'regex:/^[A-Za-z0-9 ]+$/'],
            /** PayPal: the account email. */
            'destination.email' => [Rule::requiredIf(! $bank), 'email', 'max:255'],
        ];
    }
}
