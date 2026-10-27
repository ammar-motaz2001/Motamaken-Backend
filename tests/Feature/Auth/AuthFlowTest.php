<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\OtpCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Secret#123';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_email_signup_verify_and_login_with_otp(): void
    {
        $this->postJson('/api/auth/signup', [
            'method' => 'email',
            'firstName' => 'Ammar',
            'lastName' => 'Motaz',
            'username' => 'Ammar',
            'email' => ' Ammar@Example.com ',
            'password' => self::PASSWORD,
            'subscribe' => true,
        ])->assertOk()->assertExactJson(['target' => 'ammar@example.com']);

        $this->verify('signup', 'email', 'ammar@example.com')->assertOk()->assertExactJson(['verified' => true]);

        $user = User::firstWhere('email', 'ammar@example.com');
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(50, $user->points);
        $this->assertTrue($user->subscribed);

        // Verified: the password is enough, no code is sent.
        $token = $this->login('email', 'ammar@example.com')
            ->assertOk()
            ->assertJsonPath('otpRequired', false)
            ->assertJsonPath('user.email', 'ammar@example.com')
            ->json('accessToken');

        $this->withToken($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.points', 50);
    }

    public function test_verified_phone_user_logs_in_without_otp(): void
    {
        User::factory()->withPhone('+966501234567')->create(['password' => self::PASSWORD]);

        $this->login('phone', '+966 50 123 4567')
            ->assertOk()
            ->assertJsonPath('otpRequired', false)
            ->assertJsonStructure(['accessToken', 'user' => ['id', 'phone']]);

        Notification::assertNothingSent();
    }

    public function test_a_login_code_can_be_required_for_a_method_by_configuration(): void
    {
        config(['auth_flow.login_otp_methods' => ['email']]);
        User::factory()->create(['email' => 'coded@example.com', 'password' => self::PASSWORD]);

        $this->login('email', 'coded@example.com')->assertOk()->assertExactJson(['otpRequired' => true]);
        $this->verify('login', 'email', 'coded@example.com')->assertOk()->assertJsonStructure(['accessToken']);
    }

    public function test_unverified_account_must_verify_on_login_and_gets_welcome_points(): void
    {
        $user = User::factory()->withPhone()->create(['password' => self::PASSWORD, 'phone_verified_at' => null]);

        $this->login('phone', '+966501234567')->assertOk()->assertExactJson(['otpRequired' => true]);
        $this->verify('login', 'phone', '+966501234567')->assertOk()->assertJsonStructure(['accessToken']);

        $this->assertNotNull($user->fresh()->phone_verified_at);
        $this->assertSame(50, $user->fresh()->points);
    }

    public function test_wrong_password_is_rejected_with_message(): void
    {
        User::factory()->create(['email' => 'a@example.com', 'password' => self::PASSWORD]);

        $this->postJson('/api/auth/login', ['method' => 'email', 'identifier' => 'a@example.com', 'password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The email or password is incorrect.');
    }

    public function test_signup_with_a_verified_identifier_is_rejected(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/auth/signup', [
            'method' => 'email', 'username' => 'Someone', 'email' => 'taken@example.com', 'password' => self::PASSWORD,
        ])->assertStatus(422);
    }

    public function test_signup_can_be_retried_for_an_unverified_identifier(): void
    {
        $payload = ['method' => 'email', 'firstName' => 'Retry', 'lastName' => 'User', 'username' => 'First', 'email' => 'retry@example.com', 'password' => self::PASSWORD];

        $this->postJson('/api/auth/signup', $payload)->assertOk();
        $this->postJson('/api/auth/signup', [...$payload, 'username' => 'Second'])->assertOk();

        $this->assertSame(1, User::count());
        $this->assertSame('Second', User::first()->username);
    }

    public function test_code_is_locked_after_too_many_wrong_attempts(): void
    {
        $email = User::factory()->create()->email;
        $this->postJson('/api/auth/password/forgot', ['method' => 'email', 'target' => $email]);
        $code = $this->lastCode();
        $wrong = $code === '1111' ? '2222' : '1111';

        foreach (range(1, 4) as $_) {
            $this->verify('reset', 'email', $email, $wrong)->assertStatus(422);
        }
        $this->verify('reset', 'email', $email, $wrong)->assertStatus(429);

        // Even the right code no longer works.
        $this->verify('reset', 'email', $email, $code)->assertStatus(422);
    }

    public function test_resend_respects_cooldown_and_does_not_reveal_accounts(): void
    {
        $user = User::factory()->create();
        $this->postJson('/api/auth/password/forgot', ['method' => 'email', 'target' => $user->email])->assertOk();

        $this->postJson('/api/auth/otp/resend', ['purpose' => 'reset', 'method' => 'email', 'target' => $user->email])
            ->assertStatus(429);

        $this->travel(61)->seconds();
        $this->postJson('/api/auth/otp/resend', ['purpose' => 'reset', 'method' => 'email', 'target' => $user->email])
            ->assertOk()->assertExactJson(['sent' => true]);
        Notification::assertSentOnDemandTimes(OtpCode::class, 2);

        // No pending code: same answer, nothing sent.
        $this->postJson('/api/auth/otp/resend', ['purpose' => 'login', 'method' => 'email', 'target' => 'nobody@example.com'])
            ->assertOk()->assertExactJson(['sent' => true]);
        Notification::assertSentOnDemandTimes(OtpCode::class, 2);
    }

    public function test_password_reset_flow(): void
    {
        $user = User::factory()->create(['password' => 'Old#Pass123']);
        $oldToken = $user->createToken('web')->plainTextToken;

        // No token is returned before the code is verified, for email too.
        $this->postJson('/api/auth/password/forgot', ['method' => 'email', 'target' => $user->email])
            ->assertOk()->assertExactJson(['sent' => true]);

        $resetToken = $this->verify('reset', 'email', $user->email)->assertOk()->json('token');

        $this->postJson('/api/auth/password/reset', ['token' => $resetToken, 'password' => 'weak'])->assertStatus(422);
        $this->postJson('/api/auth/password/reset', ['token' => $resetToken, 'password' => 'New#Pass456'])
            ->assertOk()->assertExactJson(['success' => true]);
        $this->postJson('/api/auth/password/reset', ['token' => $resetToken, 'password' => 'New#Pass456'])->assertStatus(422);

        $this->assertCount(0, $user->tokens()->get(), "Old token {$oldToken} should be revoked");
        $this->postJson('/api/auth/login', ['method' => 'email', 'identifier' => $user->email, 'password' => 'New#Pass456'])
            ->assertOk();
    }

    public function test_forgot_password_for_unknown_account_sends_nothing(): void
    {
        $this->postJson('/api/auth/password/forgot', ['method' => 'email', 'target' => 'nobody@example.com'])
            ->assertOk()->assertExactJson(['sent' => true]);

        Notification::assertNothingSent();
    }

    public function test_errors_follow_accept_language(): void
    {
        $this->postJson('/api/auth/signup', ['method' => 'email'], ['Accept-Language' => 'ar'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'حقل البريد الإلكتروني مطلوب.');
    }

    public function test_logout_revokes_the_token(): void
    {
        $token = User::factory()->create()->createToken('web')->plainTextToken;

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    private function login(string $method, string $identifier): TestResponse
    {
        return $this->postJson('/api/auth/login', [
            'method' => $method, 'identifier' => $identifier, 'password' => self::PASSWORD,
        ]);
    }

    private function verify(string $purpose, string $method, string $target, ?string $code = null): TestResponse
    {
        return $this->postJson('/api/auth/otp/verify', [
            'purpose' => $purpose, 'method' => $method, 'target' => $target, 'code' => $code ?? $this->lastCode(),
        ]);
    }

    private function lastCode(): string
    {
        $sent = Notification::sent(new AnonymousNotifiable, OtpCode::class);

        return $sent->last()->code;
    }
}
