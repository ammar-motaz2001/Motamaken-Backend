<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchRequest extends FormRequest
{
    public const TYPES = ['all', 'projects', 'influencer_requests', 'influencers', 'freelancers'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** The "All Services" dropdown of the search bar. Defaults to `all`. */
            'type' => ['nullable', Rule::in(self::TYPES)],
            'q' => ['nullable', 'string', 'max:100'],
            'countryId' => ['nullable', 'integer', 'exists:countries,id'],
            'cityId' => ['nullable', 'integer', 'exists:cities,id'],
            /** Results per page when `type` is not `all`. */
            'perPage' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
