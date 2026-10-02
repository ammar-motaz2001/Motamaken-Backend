<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePreferencesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'locale' => ['sometimes', Rule::in(array_column(config('marketplace.languages'), 'code'))],
            'currency' => ['sometimes', Rule::in(array_column(config('marketplace.currencies'), 'code'))],
            'theme' => ['sometimes', Rule::in(['light', 'dark'])],
        ];
    }
}
