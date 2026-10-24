<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    public function test_admins_edit_a_users_details(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);
        User::factory()->create(['email' => 'taken@example.com']);

        $this->as($this->admin)->patchJson("/api/admin/users/{$user->id}", ['firstName' => 'سارة', 'email' => 'new@example.com', 'phone' => '+966501234567'])
            ->assertOk()
            ->assertJsonPath('data.email', 'new@example.com');

        $user->refresh();
        $this->assertSame('سارة', $user->first_name);
        $this->assertSame('+966501234567', $user->phone);
        $this->assertNotNull($user->email_verified_at);

        $this->as($this->admin)->patchJson("/api/admin/users/{$user->id}", ['email' => 'taken@example.com'])->assertUnprocessable();
        $this->as($this->admin)->patchJson("/api/admin/users/{$user->id}", ['email' => null, 'phone' => null])->assertJsonPath('code', 'needs_identifier');
    }

    public function test_admins_set_a_password_and_the_user_is_signed_out(): void
    {
        $user = User::factory()->create();
        $user->createToken('phone');

        $this->as($this->admin)->postJson("/api/admin/users/{$user->id}/password", ['password' => 'weak'])->assertUnprocessable();
        $this->as($this->admin)->postJson("/api/admin/users/{$user->id}/password", ['password' => 'N3w-Passw0rd!'])->assertOk();

        $this->assertTrue(Hash::check('N3w-Passw0rd!', $user->refresh()->password));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_only_accounts_without_history_can_be_deleted(): void
    {
        $spam = User::factory()->create();
        $client = User::factory()->create();
        Project::factory()->create(['user_id' => $client->id]);

        $this->as($this->admin)->deleteJson("/api/admin/users/{$client->id}")->assertJsonPath('code', 'user_has_history');
        $this->assertModelExists($client);

        $this->as($this->admin)->deleteJson("/api/admin/users/{$spam->id}")->assertOk();
        $this->assertModelMissing($spam);
    }

    public function test_staff_accounts_and_view_only_roles_cannot_be_used_here(): void
    {
        $staff = User::factory()->create(['is_admin' => true]);
        $this->as($this->admin)->deleteJson("/api/admin/users/{$staff->id}")->assertJsonPath('code', 'cannot_manage_admin');

        $viewer = User::factory()->create([
            'is_admin' => true,
            'admin_role_id' => AdminRole::create(['slug' => 'viewer', 'name_en' => 'Viewer', 'name_ar' => 'عرض', 'permissions' => ['users.view']])->id,
        ]);
        $user = User::factory()->create();

        $this->as($viewer)->getJson("/api/admin/users/{$user->id}")->assertOk();
        $this->as($viewer)->patchJson("/api/admin/users/{$user->id}", ['firstName' => 'X'])->assertForbidden();
        $this->as($viewer)->deleteJson("/api/admin/users/{$user->id}")->assertForbidden();
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'sanctum');
    }
}
