<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class OAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_callback_creates_user_and_code_is_exchanged_once_for_a_token(): void
    {
        $this->fakeProviderUser(email: 'Sara@Example.com');

        $code = $this->callbackCode();

        $this->postJson('/api/auth/oauth/exchange', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('user.email', 'sara@example.com')
            ->assertJsonPath('user.firstName', 'Sara')
            ->assertJsonPath('user.points', 50)
            ->assertJsonStructure(['accessToken']);

        $this->postJson('/api/auth/oauth/exchange', ['code' => $code])->assertStatus(422);
    }

    public function test_returning_provider_user_gets_the_same_account(): void
    {
        $this->fakeProviderUser(email: 'sara@example.com');
        $this->callbackCode();
        $this->callbackCode();

        $this->assertSame(1, User::count());
        $this->assertSame(50, User::first()->points);
    }

    public function test_links_to_unverified_signup_and_drops_its_password(): void
    {
        $squatter = User::factory()->unverified()->create(['email' => 'sara@example.com', 'password' => 'Squat#123']);
        $this->fakeProviderUser(email: 'sara@example.com');

        $this->callbackCode();

        $squatter->refresh();
        $this->assertNull($squatter->password);
        $this->assertNotNull($squatter->email_verified_at);
        $this->assertSame(1, $squatter->socialAccounts()->count());
    }

    public function test_unconfigured_provider_redirects_back_with_error(): void
    {
        config(['services.google.client_id' => null]);

        $this->get('/api/auth/oauth/google')
            ->assertRedirect(config('auth_flow.frontend_url').'/oauth/callback?error=provider_unavailable');
    }

    private function fakeProviderUser(?string $email): void
    {
        $user = (new SocialiteUser)->map(['id' => 'g-123', 'name' => 'Sara Ahmed 2', 'email' => $email]);

        Socialite::shouldReceive('driver->user')->andReturn($user);
    }

    private function callbackCode(): string
    {
        $location = $this->get('/api/auth/oauth/google/callback')->assertRedirect()->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        return $query['code'];
    }
}
