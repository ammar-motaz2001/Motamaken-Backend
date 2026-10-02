<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class PayoutRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** The bank transfer / PayPal reference of the payout. */
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
