<?php

namespace App\Http\Requests;

use App\Models\Proposal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProposalListRequest extends FormRequest
{
    /**
     * Validates the status filter and page size of an offers list.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in([Proposal::STATUS_PENDING, Proposal::STATUS_ACCEPTED, Proposal::STATUS_DECLINED, Proposal::STATUS_WITHDRAWN])],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
