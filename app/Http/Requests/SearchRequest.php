<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchRequest extends FormRequest
{
    public const TYPES = ['all', 'projects', 'influencer_requests', 'influencers', 'freelancers'];

    /**
     * Validates the search type, text, filters, sort and page size.
     *
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
            /** Projects and requests: a category slug ("Choose Section"). */
            'category' => ['nullable', 'string', 'max:80'],
            /** Projects and requests: budgets that overlap this range. */
            /** The currency of `budgetMin`/`budgetMax` (the visitor's display currency); US dollars when absent. */
            'currency' => ['nullable', Rule::in(array_column(config('marketplace.currencies'), 'code'))],
            'budgetMin' => ['nullable', 'numeric', 'min:0'],
            'budgetMax' => ['nullable', 'numeric', 'min:0', ...($this->filled('budgetMin') ? ['gte:budgetMin'] : [])],
            /** Freelancers and influencers: a skill they list (partial match, any case). */
            'skill' => ['nullable', 'string', 'max:40'],
            /** Freelancers and influencers: at least this many stars. */
            'minRating' => ['nullable', 'numeric', 'between:0,5'],
            /** Influencers: they publish on this platform (`instagram`, `tiktok`, ...). */
            'platform' => ['nullable', 'string', 'max:20'],
            /** Projects: `newest` (default), `budget_high`, `budget_low`. Profiles: `rating` (default), `reviews`, `newest`. */
            'sort' => ['nullable', Rule::in(['newest', 'budget_high', 'budget_low', 'rating', 'reviews'])],
            /** Results per page when `type` is not `all`. */
            'perPage' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
