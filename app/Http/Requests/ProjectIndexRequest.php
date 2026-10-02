<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectIndexRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** `project` for client projects, `influencer_request` for requests to influencers. */
            'type' => ['nullable', Rule::in([Project::TYPE_PROJECT, Project::TYPE_INFLUENCER_REQUEST])],
            /** Category or platform `slug` from `GET /categories`, e.g. `website-it-software` or `instagram`. */
            'category' => ['nullable', 'string', 'exists:categories,slug'],
            /** Searches title and description. */
            'q' => ['nullable', 'string', 'max:100'],
            'countryId' => ['nullable', 'integer', 'exists:countries,id'],
            'cityId' => ['nullable', 'integer', 'exists:cities,id'],
            /** Only projects whose budget range reaches at least this amount. */
            'budgetMin' => ['nullable', 'integer', 'min:0'],
            /** Only projects whose budget range starts at or below this amount. */
            'budgetMax' => ['nullable', 'integer', 'min:0'],
            'sort' => ['nullable', Rule::in(['latest', 'budget_high', 'budget_low'])],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
