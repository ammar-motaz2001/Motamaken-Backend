<?php

namespace App\Support;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Notification emails in Mutamakin's design (resources/views/emails/branded.blade.php): logo, brand colours, light
 * and dark themes and right-to-left for Arabic, with a plain-text part. Build the message as usual with greeting(),
 * line() and action(); extra slots (heading, code, quote, preheader) go in the data array.
 */
class BrandedMail
{
    /** The HTML and plain-text views every email is rendered with. */
    public const VIEWS = ['html' => 'emails.branded', 'text' => 'emails.branded-text'];

    /**
     * A mail message rendered with the branded views.
     *
     * @param  array<string, mixed>  $data  heading, code, quote or preheader
     */
    public static function message(array $data = []): MailMessage
    {
        return (new MailMessage)->view(self::VIEWS, $data);
    }
}
