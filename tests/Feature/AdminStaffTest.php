<?php

namespace Tests\Feature;

use App\Models\AdminLog;
use App\Models\AdminRole;
use App\Models\BlacklistEntry;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminStaffTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['is_admin' => true, 'first_name' => 'Olive', 'last_name' => 'Owner']);
    }

    public function test_roles_limit_what_a_staff_member_can_open(): void
    {
        $support = User::factory()->create(['is_admin' => true, 'admin_role_id' => AdminRole::where('slug', 'support')->value('id')]);

        $this->as($support)->getJson('/api/auth/me')->assertJsonPath('data.adminPermissions', ['overview', 'users.view', 'support', 'notifications']);
        $this->as($support)->getJson('/api/admin/users')->assertOk();
        $this->as($support)->getJson('/api/admin/contact-messages')->assertOk();
        $this->as($support)->getJson('/api/admin/withdrawals')->assertForbidden()->assertJsonPath('code', 'admin_forbidden');
        $this->as($support)->getJson('/api/admin/staff')->assertForbidden();
        $this->as($support)->postJson('/api/admin/users/'.$this->owner->id.'/suspend', ['reason' => 'nope'])->assertForbidden();
        $this->as($support)->getJson('/api/admin/export/ledger')->assertForbidden();

        // An admin without a role is an owner: everything.
        $this->as($this->owner)->getJson('/api/auth/me')->assertJsonPath('data.adminPermissions', config('admin.permissions'));
        $this->as($this->owner)->getJson('/api/admin/staff')->assertOk()->assertJsonPath('data.0.fullAccess', true);
    }

    public function test_owners_manage_staff_and_roles(): void
    {
        $sam = User::factory()->create(['email' => 'sam@example.com']);
        $finance = AdminRole::where('slug', 'finance')->value('id');

        $this->as($this->owner)->postJson('/api/admin/staff', ['identifier' => 'sam@example.com', 'roleId' => $finance])->assertCreated();
        $this->assertTrue($sam->fresh()->is_admin);
        $this->assertTrue($sam->fresh()->canAdmin('finance.manage'));
        $this->assertFalse($sam->fresh()->canAdmin('content'));

        // A new account straight from the panel.
        $this->as($this->owner)->postJson('/api/admin/staff', ['identifier' => 'new.staff@example.com', 'name' => 'New Staff', 'password' => 'Very-Secret-123'])->assertCreated();
        $this->assertTrue(User::where('email', 'new.staff@example.com')->value('is_admin'));
        $this->as($this->owner)->postJson('/api/admin/staff', ['identifier' => '+966500000000'])->assertStatus(422)->assertJsonPath('code', 'staff_not_found');

        // Custom roles.
        $role = $this->as($this->owner)->postJson('/api/admin/roles', ['nameEn' => 'Reviewer', 'nameAr' => 'مراجع', 'permissions' => ['kyc', 'reports']])
            ->assertCreated()->json('data.id');
        $this->as($this->owner)->patchJson("/api/admin/staff/{$sam->id}", ['roleId' => $role])->assertOk();
        $this->assertTrue($sam->fresh()->canAdmin('kyc'));
        $this->assertFalse($sam->fresh()->canAdmin('finance.manage'));
        $this->as($this->owner)->deleteJson("/api/admin/roles/{$role}")->assertStatus(422);
        $this->as($this->owner)->patchJson('/api/admin/roles/'.AdminRole::where('slug', 'super_admin')->value('id'), ['permissions' => ['kyc']])->assertStatus(422);

        // Taking the panel away signs them out and keeps a normal account.
        $sam->createToken('web');
        $this->as($this->owner)->patchJson("/api/admin/staff/{$sam->id}", ['active' => false])->assertOk();
        $this->assertFalse($sam->fresh()->is_admin);
        $this->assertSame(0, $sam->tokens()->count());

        // Nobody changes their own access, and the last owner keeps full access.
        $this->as($this->owner)->patchJson("/api/admin/staff/{$this->owner->id}", ['active' => false])->assertJsonPath('code', 'staff_self');
        $other = User::factory()->create(['is_admin' => true, 'admin_role_id' => $finance]);
        $this->as($other->fresh())->patchJson("/api/admin/staff/{$this->owner->id}", ['active' => false])->assertForbidden();
    }

    public function test_every_admin_change_is_written_to_the_audit_log(): void
    {
        $user = User::factory()->create();

        $this->as($this->owner)->postJson("/api/admin/users/{$user->id}/suspend", ['reason' => 'Spamming people', 'days' => 3])->assertOk();
        $this->as($this->owner)->putJson('/api/admin/settings', ['siteFeePercent' => 12])->assertOk();
        $this->as($this->owner)->getJson('/api/admin/users')->assertOk(); // reads are not logged

        $log = AdminLog::orderBy('id')->get();
        $this->assertSame(['users.suspend', 'settings.update'], $log->pluck('action')->all());
        $this->assertSame(['users', $user->id], [$log[0]->subject_type, $log[0]->subject_id]);
        $this->assertSame('Spamming people', $log[0]->changes['input']['reason']);
        $this->assertEquals(10, $log[1]->changes['before']['siteFeePercent']);

        $this->as($this->owner)->getJson('/api/admin/audit?action=users')->assertOk()
            ->assertJsonPath('data.0.action', 'users.suspend')
            ->assertJsonPath('data.0.admin.fullName', 'Olive Owner');
        $this->as($this->owner)->getJson("/api/admin/users/{$user->id}/activity")->assertJsonPath('data.adminLog.0.action', 'users.suspend');
    }

    public function test_a_timed_suspension_lifts_itself_and_a_ban_blacklists(): void
    {
        $user = User::factory()->create(['email' => 'troll@example.com']);

        $this->as($this->owner)->postJson("/api/admin/users/{$user->id}/suspend", ['reason' => 'Cooling off', 'days' => 2])->assertOk()
            ->assertJsonPath('data.suspendedUntil', fn ($until) => $until !== null);
        $this->as($user->fresh())->getJson('/api/me/summary')->assertForbidden()->assertJsonPath('code', 'account_suspended');

        $this->travel(3)->days();
        $this->as($user->fresh())->getJson('/api/me/summary')->assertOk();
        $this->assertNull($user->fresh()->suspended_at);
        $this->travelBack();

        $this->as($this->owner)->postJson("/api/admin/users/{$user->id}/ban", ['reason' => 'Fraud', 'blacklist' => true])->assertOk()
            ->assertJsonPath('data.suspendedUntil', null);
        $this->assertTrue(BlacklistEntry::blocksIdentifier('Troll@Example.com'));
        $this->postJson('/api/auth/quick/start', ['method' => 'email', 'email' => 'troll@example.com'])->assertJsonValidationErrors('email');
    }

    public function test_staff_notes_and_sessions(): void
    {
        $user = User::factory()->create();
        $user->createToken('phone');

        $note = $this->as($this->owner)->postJson("/api/admin/users/{$user->id}/notes", ['body' => 'Called them about the refund.'])->assertOk()->json('data.id');
        $this->as($this->owner)->getJson("/api/admin/users/{$user->id}/activity")->assertOk()
            ->assertJsonPath('data.notes.0.body', 'Called them about the refund.')
            ->assertJsonCount(1, 'data.sessions');
        $this->as($this->owner)->deleteJson("/api/admin/users/{$user->id}/notes/{$note}")->assertOk();
        $this->as($this->owner)->postJson("/api/admin/users/{$user->id}/sessions/revoke")->assertJsonPath('revoked', 1);
    }

    public function test_users_are_filtered_by_type_and_activity(): void
    {
        $client = User::factory()->create(['first_name' => 'Cleo', 'last_name' => 'Client', 'last_seen_at' => now()]);
        $freelancer = User::factory()->create(['first_name' => 'Fay', 'last_name' => 'Freelancer', 'last_seen_at' => now()->subDays(60)]);
        $freelancer->profile()->create(['type' => 'freelancer']);

        $names = fn (string $query) => array_column($this->as($this->owner)->getJson("/api/admin/users?{$query}")->assertOk()->json('data'), 'fullName');

        $this->assertSame(['Fay Freelancer'], $names('type=freelancer'));
        $this->assertContains('Cleo Client', $names('type=client'));
        $this->assertNotContains('Fay Freelancer', $names('type=client'));
        $this->assertContains('Fay Freelancer', $names('activity=inactive'));
        $this->assertNotContains('Fay Freelancer', $names('activity=week'));
        $this->assertSame(['Olive Owner'], $names('status=staff'));
    }

    public function test_the_two_factor_policy_closes_the_panel_to_staff_without_it(): void
    {
        Setting::put('admin.require_2fa', true);

        $this->as($this->owner)->getJson('/api/admin/overview')->assertForbidden()->assertJsonPath('code', 'admin_2fa_required');

        $this->owner->forceFill(['two_factor_method' => 'email', 'two_factor_target' => $this->owner->email, 'two_factor_enabled_at' => now()])->save();
        $this->as($this->owner->fresh())->getJson('/api/admin/overview')->assertOk();
    }

    public function test_maintenance_mode_closes_the_site_to_everyone_but_the_staff(): void
    {
        Project::factory()->create();
        $this->as($this->owner)->putJson('/api/admin/site-settings', ['maintenance' => ['enabled' => true, 'messageEn' => 'Back at 6 pm']])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/projects')->assertStatus(503)->assertJsonPath('code', 'maintenance')->assertJsonPath('message', 'Back at 6 pm');
        $this->as(User::factory()->create())->getJson('/api/me/summary')->assertStatus(503);
        $this->getJson('/api/site-settings')->assertOk()->assertJsonPath('data.maintenance.enabled', true);
        $this->postJson('/api/auth/login', [])->assertStatus(422); // signing in still works
        $this->as($this->owner)->getJson('/api/projects')->assertOk();

        $this->as($this->owner)->putJson('/api/admin/site-settings', ['maintenance' => ['enabled' => false]])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/projects')->assertOk();
    }

    private function as(User $user): static
    {
        return $this->actingAs($user, 'sanctum');
    }
}
