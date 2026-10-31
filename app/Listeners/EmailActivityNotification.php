<?php

namespace App\Listeners;

use App\Models\User;
use App\Notifications\ActivityEmail;
use App\Notifications\ActivityNotification;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * After a bell notification is stored, email it too when the user wants that group by email. Every bell notification
 * counts (activity, offers, new conversations), not only ActivityNotification.
 */
class EmailActivityNotification
{
    /**
     * Sends the email copy, in the user's language, only for stored activity notifications of a group they opted into.
     */
    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database' || ! $event->notifiable instanceof User || ! method_exists($event->notification, 'toArray')) {
            return;
        }

        $user = $event->notifiable;
        $data = $event->notification->toArray($user);
        $group = isset($data['type']) ? ActivityNotification::groupOf((string) $data['type']) : null;

        if ($group && $user->email && $user->wantsEmailFor($group)) {
            $user->notify((new ActivityEmail($data))->locale($user->locale ?: config('app.locale')));
        }
    }
}
