<?php

namespace App\Channels;

use App\Contracts\WhatsAppSender;
use Illuminate\Notifications\Notification;

class WhatsAppChannel
{
    public function __construct(private readonly WhatsAppSender $sender) {}

    /**
     * Send the notification's WhatsApp text to the user's phone, if they have one.
     */
    public function send(object $notifiable, Notification $notification): void
    {
        if ($phone = $notifiable->routeNotificationFor('whatsapp', $notification)) {
            $this->sender->send($phone, $notification->toWhatsApp($notifiable));
        }
    }
}
