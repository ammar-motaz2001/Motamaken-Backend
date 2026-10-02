<?php

namespace App\Notifications;

use App\Channels\WhatsAppChannel;
use App\Enums\OtpPurpose;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OtpCode extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly OtpPurpose $purpose,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable->routeNotificationFor('mail') ? ['mail'] : [WhatsAppChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__("auth_flow.otp_subject.{$this->purpose->value}"))
            ->line(__('auth_flow.otp_line'))
            ->line("**{$this->code}**")
            ->line(__('auth_flow.otp_expiry', ['minutes' => config('auth_flow.otp.ttl')]));
    }

    public function toWhatsApp(object $notifiable): string
    {
        return __('auth_flow.otp_whatsapp', [
            'code' => $this->code,
            'minutes' => config('auth_flow.otp.ttl'),
        ]);
    }
}
