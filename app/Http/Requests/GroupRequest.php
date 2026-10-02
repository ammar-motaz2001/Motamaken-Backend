<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GroupRequest extends FormRequest
{
    public const MAX_MEMBERS = 20;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** The group's name. */
            'title' => [$this->isMethod('POST') && $this->routeIs('conversations.group') ? 'required' : 'sometimes', 'string', 'min:2', 'max:80'],
            /** Who to add (you are always in). Up to 19 people. */
            'userIds' => ['required', 'array', 'min:1', 'max:'.(self::MAX_MEMBERS - 1)],
            'userIds.*' => ['integer', 'distinct', 'exists:users,id', 'not_in:'.$this->user()->id],
            /** The project the group is about, if any. */
            'projectId' => ['nullable', 'integer', 'exists:projects,id'],
        ];
    }
}
