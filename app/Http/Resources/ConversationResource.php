<?php

namespace App\Http\Resources;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    /**
     * Returns the conversation with its participants, last message preview, unread count and project.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $last = $this->lastMessage;

        return [
            'id' => $this->id,
            /** `direct` (two people) or `group`. */
            'type' => $this->type,
            /** The group's name. Null for direct chats. */
            'title' => $this->title,
            /** Direct chats: the other person (name, avatar, green online dot). Null for groups. */
            'participant' => $this->isGroup() ? null : new UserBriefResource($this->counterpart($request->user())),
            /** Groups: every member. Null for direct chats. */
            'participants' => $this->isGroup() ? UserBriefResource::collection($this->participants) : null,
            /** Groups: the person who created it and manages the members. */
            'createdBy' => $this->when($this->isGroup(), fn () => $this->created_by),
            'lastMessage' => $last ? [
                'id' => $last->id,
                'type' => $last->type,
                /** Up to 60 characters of the text; null for voice messages and attachments without a caption. */
                'preview' => $last->body ? Str::limit($last->body, 60) : null,
                'isMine' => $last->sender_id === $request->user()->id,
                'createdAt' => $last->created_at,
            ] : null,
            /** Messages from the other person you have not read (the red badge). */
            'unreadCount' => (int) $this->unread_count,
            /** The ad this conversation started from, if any. */
            'project' => $this->whenLoaded('project', fn () => $this->project ? [
                'id' => $this->project->id,
                'title' => $this->project->title,
            ] : null),
            'updatedAt' => $this->last_message_at ?? $this->created_at,
        ];
    }
}
