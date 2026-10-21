<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConversationIndexRequest extends FormRequest
{
    /**
     * Validates the search, sort and page size of the conversations list.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** "Search messages": matches the other person's name or username. */
            'q' => ['nullable', 'string', 'max:100'],
            /** The sort dialog: `newest` (New to Old), `oldest` (Old to New) or `unread` (unread first). Defaults to `newest`. */
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'unread'])],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
