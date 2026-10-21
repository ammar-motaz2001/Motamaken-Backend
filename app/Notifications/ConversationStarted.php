<?php

namespace App\Notifications;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Shown in the header bell when somebody opens a conversation with you for the first time.
 */
class ConversationStarted extends Notification
{
    public function __construct(
        private readonly User $starter,
        private readonly Conversation $conversation,
    ) {
        $this->id = (string) Str::orderedUuid();
    }

    /**
     * Stored in the database for the bell list.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * The stored data: the conversation and the user who started it.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'conversation.started',
            'conversationId' => $this->conversation->id,
            'user' => [
                'id' => $this->starter->id,
                'fullName' => $this->starter->full_name,
                'avatarUrl' => $this->starter->avatar_url,
            ],
        ];
    }
}
