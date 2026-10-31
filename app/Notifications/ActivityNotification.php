<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A bell notification that carries a type and a few labels. The text is built when it is read, in the reader's language
 * (the notifications.php files in lang/en and lang/ar, keyed by type), with `:name` and every param available as placeholders.
 */
class ActivityNotification extends Notification
{
    /**
     * @param  User|null  $actor  Who it is about, shown as avatar and name.
     * @param  array<string, scalar|null>  $params  Labels for the text and ids for linking (projectId, milestoneId, ...).
     */
    public function __construct(
        private readonly string $type,
        private readonly ?User $actor,
        private readonly array $params = [],
    ) {
        // Time-ordered, so notifications created in the same second still sort in the order they happened.
        $this->id = (string) Str::orderedUuid();
    }

    /**
     * The notification type, e.g. proposal.accepted, which picks its text and settings group.
     */
    public function type(): string
    {
        return $this->type;
    }

    /**
     * The settings group it belongs to (config/notifications.php), or null when it fits none.
     */
    public function group(): ?string
    {
        return self::groupOf($this->type);
    }

    /**
     * The settings group of a notification type (config/notifications.php), or null when it fits none.
     */
    public static function groupOf(string $type): ?string
    {
        foreach (config('notifications.groups') as $group => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($type, $prefix)) {
                    return $group;
                }
            }
        }

        return null;
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
     * The stored data: type, actor and params; the text itself is built when it is read.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type,
            'user' => $this->actor ? [
                'id' => $this->actor->id,
                'fullName' => $this->actor->full_name,
                'avatarUrl' => $this->actor->avatar_url,
            ] : null,
            'params' => $this->params,
        ];
    }
}
