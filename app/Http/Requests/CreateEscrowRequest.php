<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateEscrowRequest extends FormRequest
{
    /**
     * Validates the milestones of a new escrow: each with a title, amount and optional due date.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** The stages the work is paid in. Their amounts must add up to the accepted offer. */
            'milestones' => ['required', 'array', 'min:1', 'max:20'],
            'milestones.*.title' => ['required', 'string', 'min:3', 'max:120'],
            'milestones.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'milestones.*.dueDate' => ['nullable', 'date', 'after:today'],
        ];
    }
}
