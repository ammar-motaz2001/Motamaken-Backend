<?php

namespace App\Notifications;

use App\Support\BrandedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The staff's answer to a contact-form message, emailed to the sender (who may have no account).
 */
class ContactReply extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $name,
        private readonly string $subject,
        private readonly string $reply,
        private readonly string $original,
    ) {}

    /**
     * Sent by email only.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * The admin's reply to a contact message, quoting the original.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return BrandedMail::message(['quote' => str($this->original)->limit(600)->toString(), 'preheader' => str($this->reply)->limit(120)->toString()])
            ->subject(__('contact.reply_subject', ['subject' => $this->subject]))
            ->greeting(__('notifications.email_greeting', ['name' => $this->name]))
            ->line($this->reply)
            ->action(__('notifications.email_action'), config('auth_flow.frontend_url'))
            ->line(__('contact.reply_quote'));
    }
}
