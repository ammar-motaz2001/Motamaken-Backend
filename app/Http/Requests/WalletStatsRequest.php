<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WalletStatsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Defaults to the user's preferred currency. */
            'currency' => ['nullable', Rule::in(array_column(config('marketplace.currencies'), 'code'))],
            /** How many months back, including this one. Defaults to 6. */
            'months' => ['nullable', 'integer', 'min:1', 'max:12'],
        ];
    }
}
