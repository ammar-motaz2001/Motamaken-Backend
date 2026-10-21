<?php

namespace App\Notifications;

use App\Channels\WhatsAppChannel;
use App\Enums\OtpPurpose;
use App\Support\BrandedMail;
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
     * By email when the user has one, otherwise by WhatsApp.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable->routeNotificationFor('mail') ? ['mail'] : [WhatsAppChannel::class];
    }

    /**
     * The email carrying the one-time code and how long it stays valid.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return BrandedMail::message([
            'heading' => __('mail.otp_heading'),
            'code' => $this->code,
            'preheader' => __('auth_flow.otp_whatsapp', ['code' => $this->code, 'minutes' => config('auth_flow.otp.ttl')]),
            // Shown under the code (there is no button to split the lines on).
            'outroLines' => [__('auth_flow.otp_expiry', ['minutes' => config('auth_flow.otp.ttl')])],
        ])
            ->subject(__("auth_flow.otp_subject.{$this->purpose->value}"))
            ->line(__('auth_flow.otp_line'));
    }

    /**
     * The WhatsApp text carrying the one-time code, for users who signed up by phone.
     */
    public function toWhatsApp(object $notifiable): string
    {
        return __('auth_flow.otp_whatsapp', [
            'code' => $this->code,
            'minutes' => config('auth_flow.otp.ttl'),
        ]);
    }
}
