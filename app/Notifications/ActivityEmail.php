<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email copy of a bell notification, for users who turned its group on in "Email & Notification". Queued, so a
 * slow mail server never slows the request that caused it.
 */
class ActivityEmail extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $data  The stored bell notification data (type, user, params).
     */
    public function __construct(private readonly array $data) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $params = $this->data['params'] ?? [];
        $text = __("notifications.{$this->data['type']}", ['name' => $this->data['user']['fullName'] ?? '', ...$params]);

        return (new MailMessage)
            ->subject(__('notifications.email_subject'))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->first_name ?: $notifiable->username]))
            ->line($text)
            ->action(__('notifications.email_action'), config('auth_flow.frontend_url').$this->path($params))
            ->line(__('notifications.email_footer'));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function path(array $params): string
    {
        return match (true) {
            isset($params['disputeId']) => "/disputes/{$params['disputeId']}",
            isset($params['conversationId']) => "/messages?c={$params['conversationId']}",
            isset($params['projectId']) => "/projects/{$params['projectId']}",
            isset($params['withdrawalId']), isset($params['depositId']) => '/settings/wallet',
            str_starts_with($this->data['type'], 'kyc.') => '/settings/verification',
            default => '/',
        };
    }
}
