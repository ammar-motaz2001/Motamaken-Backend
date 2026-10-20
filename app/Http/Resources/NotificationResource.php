<?php

namespace App\Http\Resources;

use App\Models\NotificationTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->data;

        $params = $data['params'] ?? [];

        return [
            'id' => $this->id,
            /** E.g. `conversation.started`, `proposal.accepted`, `milestone.released`, `withdrawal.paid`. */
            'type' => $data['type'],
            /** Ready to show, in the language of the `Accept-Language` header. */
            'message' => self::message($data),
            /** Who it is about: shown as the avatar and name in the bell dropdown. Null for system notifications. */
            'user' => $data['user'] ?? null,
            /** Present for `conversation.started`: the conversation to open. */
            'conversationId' => $data['conversationId'] ?? null,
            /** Present for `proposal.*`: the project and the offer the notification is about. */
            'projectId' => $data['projectId'] ?? $params['projectId'] ?? null,
            'proposalId' => $data['proposalId'] ?? null,
            /** Everything else needed to open the right page: `milestoneId`, `escrowId`, `withdrawalId`, `disputeId`, ... */
            'context' => $params ?: null,
            /** Announcements from the staff: the title, shown in bold above the message. */
            'title' => $data['type'] === 'admin.broadcast' ? (app()->getLocale() === 'ar' ? ($params['titleAr'] ?? null) : ($params['titleEn'] ?? null)) : null,
            'readAt' => $this->read_at,
            'createdAt' => $this->created_at,
        ];
    }

    /**
     * The text in the reader's language: the staff's wording for this type when they wrote one, else the default.
     *
     * @param  array<string, mixed>  $data
     */
    public static function message(array $data): string
    {
        $params = $data['params'] ?? [];

        if ($data['type'] === 'admin.broadcast') {
            return (string) (app()->getLocale() === 'ar' ? ($params['bodyAr'] ?? '') : ($params['bodyEn'] ?? ''));
        }

        return NotificationTemplate::text($data['type'], [
            'name' => $data['user']['fullName'] ?? '',
            'project' => $data['projectTitle'] ?? '',
            ...$params,
        ]);
    }
}
