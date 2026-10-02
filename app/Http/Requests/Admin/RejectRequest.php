<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class RejectRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Shown to the user. */
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}
