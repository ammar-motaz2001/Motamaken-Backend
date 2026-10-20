<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversationId' => $this->conversation_id,
            'senderId' => $this->sender_id,
            /** Who wrote it: for the name above a bubble in a group chat. */
            'sender' => new UserBriefResource($this->whenLoaded('sender')),
            /** True for messages the logged-in user sent (the green bubbles on the right). */
            'isMine' => $this->sender_id === $request->user()?->id,
            /** `text`, `voice` or `attachment`. */
            'type' => $this->type,
            /** The text, or the caption of a voice message / attachment. */
            'body' => $this->body,
            /** Voice message length in seconds (the "02:10" next to the waveform). */
            'duration' => $this->duration,
            'attachment' => $this->when($this->attachment_path !== null, fn () => [
                'name' => $this->attachment_name,
                'mime' => $this->attachment_mime,
                'size' => $this->attachment_size,
                /** Signed link, valid for an hour: use it directly as an `<img>`, `<audio>` or download link. */
                'url' => URL::temporarySignedRoute(
                    'messages.attachment', now()->addMinutes(config('marketplace.chat.attachment_url_minutes')), $this->id,
                ),
            ]),
            /** For your own messages: `sent` (one check) or `read` (two checks; in a group, read by everyone). Null for messages you received. */
            'status' => $this->sender_id === $request->user()?->id ? ($this->isRead() ? 'read' : 'sent') : null,
            /** Groups, your own messages: how many of the other members have read it. */
            'seenBy' => $this->when(isset($this->seen_by), fn () => $this->seen_by),
            /** Cleared by a moderator after a report: show "This message was removed". */
            'removed' => $this->removed_at !== null,
            'readAt' => $this->read_at,
            'createdAt' => $this->created_at,
        ];
    }
}
