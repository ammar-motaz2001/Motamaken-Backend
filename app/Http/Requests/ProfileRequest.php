<?php

namespace App\Http\Requests;

use App\Models\City;
use App\Models\Profile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProfileRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** `freelancer` can offer on projects; `influencer` on influencer requests. */
            'type' => ['required', Rule::in([Profile::TYPE_FREELANCER, Profile::TYPE_INFLUENCER])],
            /** E.g. "Designer", "Web Developer". */
            'jobTitle' => ['required', 'string', 'min:2', 'max:80'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'countryId' => ['nullable', 'integer', 'exists:countries,id'],
            /** Needs `countryId`. */
            'cityId' => ['nullable', 'integer', 'exists:cities,id'],
        ];
    }

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
