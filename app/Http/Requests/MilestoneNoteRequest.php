<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A note with a milestone action: optional when delivering work, required when asking for changes.
 */
class MilestoneNoteRequest extends FormRequest
{
    /**
     * Validates the note sent with the milestone action.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** What was delivered (submit), or what must change (request changes: required). */
            'note' => [$this->routeIs('milestones.changes') ? 'required' : 'nullable', 'string', 'min:5', 'max:2000'],
            // Deliveries only: links to the work, e.g. the published posts of an influencer request.
            'links' => ['nullable', 'array', 'max:5'],
            'links.*' => ['url:https', 'max:500', 'distinct'],
        ];
    }
}
