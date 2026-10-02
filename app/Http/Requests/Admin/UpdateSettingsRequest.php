<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** The platform's cut of every released milestone, in percent (the "Site fees" page). */
            'siteFeePercent' => ['sometimes', 'numeric', 'min:0', 'max:50', 'decimal:0,2'],
            'depositMin' => ['sometimes', 'numeric', 'min:1'],
            'depositMax' => ['sometimes', 'numeric', 'gt:depositMin'],
            'withdrawalMin' => ['sometimes', 'numeric', 'min:1'],
            /** Flat fee added to every withdrawal. */
            'withdrawalFee' => ['sometimes', 'numeric', 'min:0'],
            /** Whether users must pass the identity check before withdrawing. */
            'withdrawalRequiresKyc' => ['sometimes', 'boolean'],
        ];
    }
}
