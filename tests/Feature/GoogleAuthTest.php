<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'client-123']);
    }

    private function fakeGoogle(array $claims = []): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response([
            'aud' => 'client-123', 'iss' => 'https://accounts.google.com', 'exp' => (string) (time() + 600),
            'email' => 'Sara@Example.com', 'email_verified' => 'true', 'given_name' => 'Sara', 'family_name' => 'Ahmed', 'name' => 'Sara Ahmed',
            ...$claims,
        ])]);
    }

    public function test_new_email_gets_a_verified_account(): void
    {
        $this->fakeGoogle();

        $this->postJson('/api/auth/google', ['credential' => 'token'])
            ->assertOk()->assertJsonPath('isNew', true)->assertJsonStructure(['accessToken']);

        $user = User::where('email', 'sara@example.com')->firstOrFail();
        $this->assertSame('Sara', $user->first_name);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_existing_account_signs_in(): void
    {
        $user = User::factory()->create(['email' => 'sara@example.com']);
        $this->fakeGoogle();

        $this->postJson('/api/auth/google', ['credential' => 'token'])
            ->assertOk()->assertJsonPath('isNew', false)->assertJsonPath('user.id', $user->id);
    }

    public function test_token_for_another_site_is_refused(): void
    {
        $this->fakeGoogle(['aud' => 'someone-else']);

        $this->postJson('/api/auth/google', ['credential' => 'token'])->assertStatus(422);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_unverified_google_email_is_refused(): void
    {
        $this->fakeGoogle(['email_verified' => 'false']);

        $this->postJson('/api/auth/google', ['credential' => 'token'])->assertStatus(422);
    }

    public function test_off_without_a_client_id(): void
    {
        config(['services.google.client_id' => null]);

        $this->postJson('/api/auth/google', ['credential' => 'token'])->assertNotFound();
    }
}
