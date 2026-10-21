<?php

namespace App\Services;

use App\Contracts\WhatsAppSender;
use Illuminate\Support\Facades\Log;

/**
 * Development stand-in: writes messages to the log. Bind a real provider (Twilio, Meta Cloud API, ...)
 * to the WhatsAppSender contract in AppServiceProvider for production.
 */
class LogWhatsAppSender implements WhatsAppSender
{
    /**
     * Write the message to the application log instead of sending it.
     */
    public function send(string $phone, string $message): void
    {
        Log::info("WhatsApp to {$phone}: {$message}");
    }
}
