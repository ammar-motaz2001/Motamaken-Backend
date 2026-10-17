<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_verified_admin_who_can_log_in(): void
    {
        $this->artisan('admin:create', ['email' => 'Boss@Example.com', '--password' => 'Strong#Pass1'])->assertSuccessful();

        $admin = User::where('email', 'boss@example.com')->first();
        $this->assertTrue((bool) $admin->is_admin);
        $this->assertNotNull($admin->email_verified_at);
        $this->postJson('/api/auth/login', ['method' => 'email', 'identifier' => 'boss@example.com', 'password' => 'Strong#Pass1'])
            ->assertOk()->assertJsonPath('user.isAdmin', true);
    }

    public function test_it_promotes_an_existing_account_and_keeps_its_password(): void
    {
        $user = User::factory()->create(['email' => 'sara@example.com', 'password' => Hash::make('Secret#123'), 'suspended_at' => now()]);

        $this->artisan('admin:create', ['email' => 'sara@example.com'])->assertSuccessful();

        $user->refresh();
        $this->assertTrue((bool) $user->is_admin);
        $this->assertNull($user->suspended_at);
        $this->assertTrue(Hash::check('Secret#123', $user->password));
        $this->assertSame(1, User::count());
    }

    public function test_weak_passwords_and_bad_emails_are_refused(): void
    {
        $this->artisan('admin:create', ['email' => 'x@example.com', '--password' => 'weak'])->assertFailed();
        $this->artisan('admin:create', ['email' => 'not-an-email'])->assertFailed();
        $this->assertSame(0, User::count());
    }
}
