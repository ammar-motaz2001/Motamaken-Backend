<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DepositRequest extends FormRequest
{
    /**
     * Validates a top-up: amount, currency, method, purpose, and a new card token or a saved card.
     *
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
            /** `topup` (default) or `purchase`: paying for something by card tops the wallet up with exactly its price, so the
             * deposit minimum does not apply (it is still at least 1). */
            'purpose' => ['nullable', Rule::in(['topup', 'purchase'])],
            /** A new card, tokenised in the browser with Moyasar's publishable key (`token_...`). Never send card numbers. */
            'cardToken' => ['nullable', 'string', 'max:100', 'regex:/^token_[A-Za-z0-9]+$/'],
            /** Or one of my saved cards (`GET /me/payment-methods`). */
            'paymentMethodId' => ['nullable', 'integer', Rule::exists('payment_methods', 'id')->where('user_id', $this->user()?->id)->where('type', 'card')],
        ];
    }
}
