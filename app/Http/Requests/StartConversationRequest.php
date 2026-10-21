<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartConversationRequest extends FormRequest
{
    /**
     * Validates who to talk to, the optional project and the first message.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** The person to talk to. */
            'userId' => ['required', 'integer', 'exists:users,id', 'not_in:'.$this->user()->id],
            /** The ad the conversation starts from ("Add Your Offer", "Hire Me"). */
            'projectId' => ['nullable', 'integer', 'exists:projects,id'],
            /** Optional first message. */
            'body' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Custom error text for trying to message yourself.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['userId.not_in' => __('chat.cannot_message_yourself')];
    }
}
