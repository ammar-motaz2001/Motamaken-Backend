<?php

namespace Tests\Feature;

use App\Enums\OtpPurpose;
use App\Models\User;
use App\Notifications\ContactReply;
use App\Notifications\OtpCode;
use Tests\TestCase;

class BrandedMailTest extends TestCase
{
    public function test_the_code_email_uses_the_branded_design_with_light_and_dark_themes(): void
    {
        $html = (string) (new OtpCode('4895', OtpPurpose::Signup))->toMail(User::factory()->make())->render();

        $this->assertStringContainsString('4895', $html);
        $this->assertStringContainsString('/images/logo.png', $html);
        $this->assertStringContainsString('prefers-color-scheme: dark', $html);
        $this->assertStringContainsString('dir="ltr"', $html);
        // The expiry note comes after the code.
        $this->assertLessThan(strpos($html, __('auth_flow.otp_expiry', ['minutes' => config('auth_flow.otp.ttl')])), strpos($html, '4895'));
    }

    public function test_arabic_emails_read_right_to_left_and_escape_what_people_wrote(): void
    {
        app()->setLocale('ar');
        $html = (string) (new ContactReply('Ahmed', 'Hi', 'Thanks <b>for</b> asking.', '<script>alert(1)</script>'))->toMail(User::factory()->make())->render();

        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('فريق متمكن', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_every_email_has_a_plain_text_part(): void
    {
        $message = (new OtpCode('4895', OtpPurpose::Signup))->toMail(User::factory()->make());

        $this->assertSame(['html' => 'emails.branded', 'text' => 'emails.branded-text'], $message->view);
        $this->assertStringContainsString('4895', view('emails.branded-text', $message->data())->render());
    }
}
