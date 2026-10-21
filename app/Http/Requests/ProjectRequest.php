<?php

namespace App\Http\Requests;

use App\Models\BlacklistEntry;
use App\Models\Category;
use App\Models\City;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Post a project (POST) or edit one (PATCH: send only what changes).
 */
class ProjectRequest extends FormRequest
{
    /**
     * Validates a project's type, category, title, description, budget, currency, location and draft flag.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            /** `project` for freelancers ("A project for Freelancers"), `influencer_request` for influencers ("A Request for Influencers"). Cannot be changed later. */
            'type' => $this->isMethod('POST')
                ? ['required', Rule::in([Project::TYPE_PROJECT, Project::TYPE_INFLUENCER_REQUEST])]
                : ['prohibited'],
            /** A `project` category, or an `influencer` platform for influencer requests (from `GET /categories`). */
            'categoryId' => [$required, 'integer', 'exists:categories,id'],
            'title' => [$required, 'string', 'min:5', 'max:150'],
            'description' => [$required, 'string', 'min:20', 'max:5000'],
            'budgetMin' => [$required, 'integer', 'min:1', 'max:1000000'],
            'budgetMax' => [$required, 'integer', 'min:1', 'max:1000000'],
            /** Defaults to `USD`. */
            'currency' => ['nullable', Rule::in(array_column(config('marketplace.currencies'), 'code'))],
            'countryId' => ['nullable', 'integer', 'exists:countries,id'],
            /** Needs `countryId`. */
            'cityId' => ['nullable', 'integer', 'exists:cities,id'],
            /** POST only: save it as a draft instead of publishing it. */
            'draft' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Checks fields against each other once each is valid on its own, such as the category matching the project type.
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $project = $this->isMethod('POST') ? null : Project::find($this->route('project'));
            $type = $this->input('type', $project?->type);

            if ($this->filled('categoryId')) {
                $expected = $type === Project::TYPE_PROJECT ? Category::TYPE_PROJECT : Category::TYPE_INFLUENCER;

                $category = Category::find($this->integer('categoryId'));

                if ($category?->type !== $expected) {
                    $validator->errors()->add('categoryId', __('proposals.category_mismatch'));
                } elseif (! $category->is_active && $category->id !== $project?->category_id) {
                    $validator->errors()->add('categoryId', __('proposals.category_archived'));
                }
            }

            foreach (['title', 'description'] as $field) {
                if ($word = BlacklistEntry::blockedWord($this->input($field))) {
                    $validator->errors()->add($field, __('admin.blocked_word', ['word' => $word]));
                }
            }

            $min = $this->input('budgetMin', $project?->budget_min);
            $max = $this->input('budgetMax', $project?->budget_max);

            if ($min !== null && $max !== null && (int) $max < (int) $min) {
                $validator->errors()->add('budgetMax', __('proposals.budget_range'));
            }

            if ($this->filled('cityId')) {
                if (! $this->filled('countryId')) {
                    $validator->errors()->add('countryId', __('proposals.country_required'));
                } elseif (! City::whereKey($this->integer('cityId'))->where('country_id', $this->integer('countryId'))->exists()) {
                    $validator->errors()->add('cityId', __('proposals.city_mismatch'));
                }
            }
        }];
    }
}
