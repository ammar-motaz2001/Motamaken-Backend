<?php

namespace App\Http\Requests;

use App\Models\Dispute;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OpenDisputeRequest extends FormRequest
{
    /**
     * Validates the reason and description of a new dispute.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** `not_delivered`, `quality`, `not_as_described`, `no_response`, `payment` or `other`. */
            'reason' => ['required', Rule::in(Dispute::REASONS)],
            /** What happened, in the opener's words. */
            'description' => ['required', 'string', 'min:20', 'max:3000'],
        ];
    }
}
