<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A note with a milestone action: optional when delivering work, required when asking for changes.
 */
class MilestoneNoteRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** What was delivered (submit), or what must change (request changes: required). */
            'note' => [$this->routeIs('milestones.changes') ? 'required' : 'nullable', 'string', 'min:5', 'max:2000'],
        ];
    }
}
