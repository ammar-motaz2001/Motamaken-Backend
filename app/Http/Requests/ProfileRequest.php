<?php

namespace App\Http\Requests;

use App\Models\City;
use App\Models\Profile;
use App\Rules\CleanText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProfileRequest extends FormRequest
{
    public const PLATFORMS = ['instagram', 'facebook', 'youtube', 'tiktok', 'snapchat', 'x', 'pinterest', 'telegram', 'whatsapp', 'linkedin'];

    /**
     * Validates the profile: type, job title, bio, location, skills, languages, experience, education and more.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** `freelancer` can offer on projects; `influencer` on influencer requests. */
            'type' => ['required', Rule::in([Profile::TYPE_FREELANCER, Profile::TYPE_INFLUENCER])],
            /** E.g. "Designer", "Web Developer". */
            'jobTitle' => ['required', 'string', 'min:2', 'max:80'],
            'bio' => ['nullable', 'string', 'max:2000', new CleanText],
            'countryId' => ['nullable', 'integer', 'exists:countries,id'],
            /** Needs `countryId`. */
            'cityId' => ['nullable', 'integer', 'exists:cities,id'],

            // The sections below are optional: a list that is sent replaces the saved one, one that is left out is kept.
            /** Up to 20 skills, e.g. `["Figma", "Branding"]`. */
            'skills' => ['sometimes', 'array', 'max:20'],
            'skills.*' => ['string', 'min:1', 'max:40', 'distinct'],
            /** `[{name, level}]`, level `native`, `fluent`, `good` or `basic`. */
            'languages' => ['sometimes', 'array', 'max:10'],
            'languages.*.name' => ['required', 'string', 'max:40'],
            'languages.*.level' => ['required', Rule::in(['native', 'fluent', 'good', 'basic'])],
            /** `[{title, company, from: "2021-03", to: null|"2023-01", description}]`. */
            'experiences' => ['sometimes', 'array', 'max:20'],
            'experiences.*.title' => ['required', 'string', 'max:80'],
            'experiences.*.company' => ['required', 'string', 'max:80'],
            'experiences.*.from' => ['required', 'date_format:Y-m'],
            'experiences.*.to' => ['nullable', 'date_format:Y-m', 'after_or_equal:experiences.*.from'],
            'experiences.*.description' => ['nullable', 'string', 'max:500'],
            /** `[{school, degree, from: 2016, to: 2020}]`. */
            'educations' => ['sometimes', 'array', 'max:10'],
            'educations.*.school' => ['required', 'string', 'max:100'],
            'educations.*.degree' => ['required', 'string', 'max:100'],
            'educations.*.from' => ['required', 'integer', 'between:1950,2100'],
            'educations.*.to' => ['nullable', 'integer', 'between:1950,2100', 'gte:educations.*.from'],
            /** `[{name, issuer, year, url}]`. */
            'certificates' => ['sometimes', 'array', 'max:20'],
            'certificates.*.name' => ['required', 'string', 'max:100'],
            'certificates.*.issuer' => ['required', 'string', 'max:100'],
            'certificates.*.year' => ['required', 'integer', 'between:1950,2100'],
            'certificates.*.url' => ['nullable', 'url', 'max:500'],
            /** Influencers: `[{platform, handle, url, followers, engagementRate}]`. */
            'channels' => ['sometimes', 'array', 'max:12'],
            'channels.*.platform' => ['required', Rule::in(self::PLATFORMS)],
            'channels.*.handle' => ['required', 'string', 'max:60'],
            'channels.*.url' => ['nullable', 'url', 'max:500'],
            'channels.*.followers' => ['required', 'integer', 'min:0', 'max:2000000000'],
            'channels.*.engagementRate' => ['nullable', 'numeric', 'between:0,100'],
        ];
    }

    /**
     * A city needs its country, and must belong to that country.
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || ! $this->filled('cityId')) {
                return;
            }

            if (! $this->filled('countryId')) {
                $validator->errors()->add('countryId', __('proposals.country_required'));
            } elseif (! City::whereKey($this->integer('cityId'))->where('country_id', $this->integer('countryId'))->exists()) {
                $validator->errors()->add('cityId', __('proposals.city_mismatch'));
            }
        }];
    }
}
