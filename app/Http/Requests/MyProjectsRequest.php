<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MyProjectsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in([Project::STATUS_DRAFT, Project::STATUS_OPEN, Project::STATUS_IN_PROGRESS, Project::STATUS_COMPLETED, Project::STATUS_CLOSED])],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
