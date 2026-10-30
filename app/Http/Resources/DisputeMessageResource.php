<?php

namespace App\Http\Resources;

use App\Models\DisputeMessage;
use App\Support\SignedUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DisputeMessage
 */
class DisputeMessageResource extends JsonResource
{
    /**
     * Returns the message with its author, text and attachment link.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** `message` (written by a side or an admin), `system` (an event) or `admin_note` (only admins see these). */
            'kind' => $this->kind,
            'author' => $this->user ? [
                'id' => $this->user->id,
                'fullName' => $this->user->full_name,
                'avatarUrl' => $this->user->avatar_url,
                'isAdmin' => (bool) $this->user->is_admin,
            ] : null,
            /** The text; for `system` entries, the event already written in the request language. */
            'body' => $this->kind === DisputeMessage::SYSTEM ? __('disputes.events.'.str_replace('dispute.', '', $this->body)) : $this->body,
            'attachment' => $this->when($this->attachment_path !== null, fn () => [
                'name' => $this->attachment_name,
                'mime' => $this->attachment_mime,
                'size' => $this->attachment_size,
                /** Signed link, valid for an hour. */
                'url' => SignedUrl::temporary(
                    'disputes.attachment', now()->addMinutes(config('marketplace.chat.attachment_url_minutes')), ['message' => $this->id],
                ),
            ]),
            'createdAt' => $this->created_at,
        ];
    }
}
