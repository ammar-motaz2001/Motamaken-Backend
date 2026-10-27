<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RecaptchaTest extends TestCase
{
    use RefreshDatabase;

    private const SIGNUP = ['method' => 'email', 'email' => 'nora@example.com', 'firstName' => 'Nora', 'lastName' => 'Saleh', 'username' => 'Nora', 'password' => 'Secret#123', 'password_confirmation' => 'Secret#123'];

    private const CONTACT = ['name' => 'Nora', 'email' => 'nora@example.com', 'topic' => 'general', 'subject' => 'Hello there', 'message' => 'A question about fees.'];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_without_keys_no_captcha_is_asked(): void
    {
        config(['services.recaptcha.secret_key' => null]);
        Http::fake();

        $this->postJson('/api/auth/signup', self::SIGNUP)->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_signup_needs_a_token_google_accepts(): void
    {
        config(['services.recaptcha.secret_key' => 'test-secret']);
        Http::fake(['www.google.com/recaptcha/*' => fn (Request $request) => Http::response(['success' => $request['response'] === 'good-token'])]);

        $this->postJson('/api/auth/signup', self::SIGNUP)->assertJsonValidationErrors('recaptchaToken');
        $this->postJson('/api/auth/signup', [...self::SIGNUP, 'recaptchaToken' => 'bad-token'])
            ->assertJsonValidationErrors(['recaptchaToken' => __('auth_flow.recaptcha_failed')]);
        $this->postJson('/api/auth/signup', [...self::SIGNUP, 'recaptchaToken' => 'good-token'])->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request['secret'] === 'test-secret' && $request['response'] === 'good-token' && filled($request['remoteip']));
    }

    public function test_google_being_down_fails_closed(): void
    {
        config(['services.recaptcha.secret_key' => 'test-secret']);
        Http::fake(['www.google.com/recaptcha/*' => Http::failedConnection()]);

        $this->postJson('/api/auth/signup', [...self::SIGNUP, 'recaptchaToken' => 'good-token'])->assertJsonValidationErrors('recaptchaToken');
    }

    public function test_guests_need_a_captcha_on_the_contact_form_but_members_do_not(): void
    {
        config(['services.recaptcha.secret_key' => 'test-secret']);
        Http::fake(['www.google.com/recaptcha/*' => Http::response(['success' => true])]);

        $this->postJson('/api/contact', self::CONTACT)->assertJsonValidationErrors('recaptchaToken');
        $this->postJson('/api/contact', [...self::CONTACT, 'recaptchaToken' => 'good-token'])->assertSuccessful();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/contact', ['topic' => 'general', 'subject' => 'Hello there', 'message' => 'A question about fees.'])->assertSuccessful();
    }

    public function test_v3_scores_below_the_threshold_are_refused(): void
    {
        config(['services.recaptcha.secret_key' => 'secret']);
        Http::fake(['www.google.com/recaptcha/*' => fn (Request $request) => Http::response([
            'success' => true, 'action' => 'contact', 'score' => $request['response'] === 'human' ? 0.9 : 0.1,
        ])]);
        $message = ['name' => 'Huda', 'email' => 'huda@example.com', 'topic' => 'general', 'subject' => 'A question', 'message' => 'How do I post a project here?'];

        $this->postJson('/api/contact', [...$message, 'recaptchaToken' => 'bot'])->assertJsonValidationErrors('recaptchaToken');
        $this->postJson('/api/contact', [...$message, 'recaptchaToken' => 'human'])->assertSuccessful();
    }
}
