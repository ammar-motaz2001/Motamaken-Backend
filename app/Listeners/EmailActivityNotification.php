<?php

namespace App\Listeners;

use App\Models\User;
use App\Notifications\ActivityEmail;
use App\Notifications\ActivityNotification;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * After a bell notification is stored, email it too when the user wants that group by email.
 */
class EmailActivityNotification
{
    /**
     * Sends the email copy, in the user's language, only for stored activity notifications of a group they opted into.
     */
    public function handle(NotificationSent $event): void
    {
        if (! $event->notification instanceof ActivityNotification || $event->channel !== 'database' || ! $event->notifiable instanceof User) {
            return;
        }

        $group = $event->notification->group();
        $user = $event->notifiable;

        if ($group && $user->email && $user->wantsEmailFor($group)) {
            $user->notify((new ActivityEmail($event->notification->toArray($user)))->locale($user->locale ?: config('app.locale')));
        }
    }
}
