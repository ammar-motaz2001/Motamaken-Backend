<?php

namespace App\Http\Requests;

use App\Models\Dispute;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveDisputeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** `client_refund` (everything back to the client), `freelancer_payout` (everything to the freelancer, minus the fee) or `split`. */
            'resolution' => ['required', Rule::in([Dispute::REFUND_CLIENT, Dispute::PAY_FREELANCER, Dispute::SPLIT])],
            /** For `split`: the percentage that goes back to the client (1 to 99). The rest goes to the freelancer. */
            'clientPercent' => [Rule::requiredIf($this->input('resolution') === Dispute::SPLIT), 'nullable', 'integer', 'between:1,99'],
            /** The decision, explained to both sides. */
            'note' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }
}
