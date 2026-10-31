<?php

namespace App\Notifications;

use App\Support\BrandedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirms to the sender of a contact-form message (who may have no account) that the team received it.
 */
class ContactReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $name,
        private readonly string $subject,
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
     * "We received your message", quoting it.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return BrandedMail::message(['quote' => str($this->original)->limit(600)->toString(), 'preheader' => __('contact.received_line')])
            ->subject(__('contact.received_subject', ['subject' => $this->subject]))
            ->greeting(__('notifications.email_greeting', ['name' => $this->name]))
            ->line(__('contact.received_line'))
            ->action(__('notifications.email_action'), config('auth_flow.frontend_url'))
            ->line(__('contact.reply_quote'));
    }
}
