<?php

namespace App\Http\Requests;

use App\Models\Campaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a campaign (POST) or change one (PATCH: send only what changes).
 */
class CampaignRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'min:3', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            /** What the links lead to: `project` (one of your open projects) or `profile` (your profile). Cannot change later. */
            'targetType' => $creating ? ['required', Rule::in([Campaign::TARGET_PROJECT, Campaign::TARGET_PROFILE])] : ['prohibited'],
            /** For `project`: which one. */
            'targetId' => $creating ? ['required_if:targetType,project', 'nullable', 'integer'] : ['prohibited'],
            'currency' => $creating ? ['required', Rule::in(array_keys(config('marketplace.campaigns.min_budget')))] : ['prohibited'],
            /** The most the campaign may spend. Taken from the wallet at launch; what is left comes back at the end. */
            'budget' => [$required, 'numeric', 'gt:0', 'decimal:0,2'],
            /** What one valid visit costs you. The promoter receives it minus the platform fee. */
            'costPerVisit' => [$required, 'numeric', 'gt:0', 'decimal:0,2'],
            'startsAt' => ['nullable', 'date', 'after_or_equal:today'],
            'endsAt' => ['nullable', 'date', 'after:startsAt', 'after:now'],
            /** The visit filtering rules. Send only the ones that change. */
            'filters' => ['sometimes', 'array'],
            /** Turn away robots and scripts. */
            'filters.block_bots' => ['sometimes', 'boolean'],
            /** Pay for the same visitor once in this many hours (1 to 720). */
            'filters.unique_window_hours' => ['sometimes', 'integer', 'between:1,720'],
            /** Most paid visits one promoter can bring per hour. */
            'filters.max_visits_per_promoter_per_hour' => ['sometimes', 'integer', 'between:1,10000'],
            /** Two-letter country codes to accept; empty for anywhere. */
            'filters.allowed_countries' => ['sometimes', 'array', 'max:50'],
            'filters.allowed_countries.*' => ['string', 'size:2', 'alpha'],
        ];
    }
}
