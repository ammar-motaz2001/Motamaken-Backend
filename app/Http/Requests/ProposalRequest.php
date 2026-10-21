<?php

namespace App\Http\Requests;

use App\Rules\CleanText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Make an offer (POST) or edit one (PATCH: send only what changes).
 */
class ProposalRequest extends FormRequest
{
    /**
     * Validates an offer's amount, delivery days, message and optional team.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            /** In the currency of the project. */
            'amount' => [$required, 'numeric', 'min:1', 'max:1000000', 'decimal:0,2'],
            /** How many days the work will take. */
            'deliveryDays' => [$required, 'integer', 'min:1', 'max:365'],
            /** The cover letter. */
            'message' => [$required, 'string', 'min:10', 'max:3000', new CleanText],
            /** Send the offer for a published team you lead: its members share the payment. Projects only. */
            'teamId' => ['nullable', 'integer', 'exists:teams,id'],
        ];
    }
}
