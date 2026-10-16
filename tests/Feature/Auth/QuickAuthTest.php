<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\OtpCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class QuickAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_a_new_email_becomes_a_verified_account_and_is_signed_in(): void
    {
        $referrer = User::factory()->create();

        $this->postJson('/api/auth/quick/start', ['method' => 'email', 'email' => 'Sara.Ahmed@Example.com'])
            ->assertOk()->assertExactJson(['sent' => true, 'target' => 'sara.ahmed@example.com']);
        $this->postJson('/api/auth/quick/verify', ['method' => 'email', 'email' => 'sara.ahmed@example.com', 'code' => '0000'.($this->lastCode() === '0000' ? '1' : '')])
            ->assertStatus(422);

        $response = $this->postJson('/api/auth/quick/verify', [
            'method' => 'email', 'email' => 'sara.ahmed@example.com', 'code' => $this->lastCode(), 'referralCode' => strtolower($referrer->referral_code),
        ])->assertOk()->assertJsonPath('isNew', true)->assertJsonPath('user.fullName', 'Sara Ahmed')->assertJsonStructure(['accessToken']);

        $user = User::where('email', 'sara.ahmed@example.com')->first();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->password, 'no password until they choose one');
        $this->assertSame($referrer->id, $user->referred_by);

        $this->app['auth']->forgetGuards();
        $this->withToken($response->json('accessToken'))->getJson('/api/auth/me')->assertJsonPath('data.id', $user->id);
    }

    public function test_an_existing_account_is_signed_in_and_codes_are_single_use(): void
    {
        $user = User::factory()->create(['phone' => '+966501234567', 'phone_verified_at' => now(), 'password' => Hash::make('Secret#123')]);

        $this->postJson('/api/auth/quick/start', ['method' => 'phone', 'phone' => '+966 50 123 4567'])->assertOk();
        $code = $this->lastCode();
        $this->postJson('/api/auth/quick/verify', ['method' => 'phone', 'phone' => '+966501234567', 'code' => $code, 'name' => 'Ignored Name'])
            ->assertOk()->assertJsonPath('isNew', false)->assertJsonPath('user.id', $user->id);
        $this->postJson('/api/auth/quick/verify', ['method' => 'phone', 'phone' => '+966501234567', 'code' => $code])->assertStatus(422);

        $this->assertTrue(Hash::check('Secret#123', $user->fresh()->password), 'the password stays');
        $this->assertSame(1, User::count());
    }

    public function test_two_step_accounts_must_use_their_password_and_quick_codes_are_kept_apart(): void
    {
        User::factory()->create(['email' => 'safe@example.com', 'two_factor_method' => 'email', 'two_factor_target' => 'safe@example.com']);
        $this->postJson('/api/auth/quick/start', ['method' => 'email', 'email' => 'safe@example.com'])->assertJsonPath('code', 'password_login_required');

        $this->postJson('/api/auth/quick/start', ['method' => 'email', 'email' => 'new@example.com'])->assertOk();
        $this->postJson('/api/auth/otp/verify', ['purpose' => 'quick', 'method' => 'email', 'target' => 'new@example.com', 'code' => $this->lastCode()])
            ->assertJsonValidationErrors('purpose');
        $this->postJson('/api/auth/quick/start', ['method' => 'email', 'email' => 'not-an-email'])->assertJsonValidationErrors('email');
    }

    public function test_login_names_an_unknown_account_and_keeps_wrong_passwords_vague(): void
    {
        User::factory()->create(['email' => 'sara@example.com', 'email_verified_at' => now(), 'password' => Hash::make('Secret#123')]);

        $this->postJson('/api/auth/login', ['method' => 'email', 'identifier' => 'nobody@example.com', 'password' => 'Secret#123'])
            ->assertStatus(422)->assertJsonPath('message', 'No account is linked to this email address.');
        $this->postJson('/api/auth/login', ['method' => 'email', 'identifier' => 'sara@example.com', 'password' => 'Wrong#1234'])
            ->assertStatus(422)->assertJsonPath('message', 'The email or password is incorrect.');
    }

    private function lastCode(): string
    {
        return Notification::sent(new AnonymousNotifiable, OtpCode::class)->last()->code;
    }
}
