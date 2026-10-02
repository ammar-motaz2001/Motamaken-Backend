<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TeamMemberRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $inviting = $this->isMethod('POST');

        return [
            /** Who to invite. They need a freelancer profile. */
            'userId' => $inviting ? ['required', 'integer', 'exists:users,id', 'not_in:'.$this->user()->id] : ['prohibited'],
            /** What they do in the team, e.g. "Designer". */
            'roleTitle' => ['nullable', 'string', 'max:80'],
            /** Their cut of every payment, in percent (1 to 98). The lead keeps the rest. Accepting the invitation approves it. */
            'sharePercent' => [$inviting ? 'required' : 'sometimes', 'numeric', 'min:1', 'max:98', 'decimal:0,2'],
        ];
    }

    public function shareBp(): ?int
    {
        return $this->has('sharePercent') ? (int) round(((float) $this->input('sharePercent')) * 100) : null;
    }
}
