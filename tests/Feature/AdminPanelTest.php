<?php

namespace Tests\Feature;

use App\Models\LedgerTransaction;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HandlesMoney;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use HandlesMoney, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true, 'first_name' => 'Ada', 'last_name' => 'Admin']);
    }

    public function test_the_dashboard_counts_everything(): void
    {
        $client = User::factory()->create();
        $freelancer = User::factory()->unverified()->create(['created_at' => now()->subDays(20)]);
        User::factory()->create(['suspended_at' => now()]);
        Project::factory()->count(2)->create();
        $this->hiredProject($client, $freelancer);
        $this->fund($client, 300);

        $this->as($this->admin)->getJson('/api/admin/overview')
            ->assertOk()
            ->assertJsonPath('users.total', 6)
            ->assertJsonPath('users.suspended', 1)
            ->assertJsonPath('users.newThisWeek', 5)
            ->assertJsonPath('projects', ['draft' => 0, 'open' => 2, 'in_progress' => 1, 'completed' => 0, 'closed' => 0])
            ->assertJsonPath('proposals.accepted', 1)
            ->assertJsonPath('usersByType.client', 5)
            ->assertJsonPath('usersByType.freelancer', 1)
            ->assertJsonPath('queues', ['pendingWithdrawals' => 0, 'pendingKyc' => 0, 'openDisputes' => 0, 'openReports' => 0, 'newContactMessages' => 0, 'pendingAppeals' => 0, 'openTickets' => 0, 'pendingDeposits' => 0])
            ->assertJsonPath('money.0', ['currency' => 'USD', 'inWallets' => 300, 'inEscrow' => 0, 'revenue' => 0, 'pendingWithdrawals' => 0])
            ->assertJsonCount(14, 'series')
            ->assertJsonPath('series.13.projects', 3)
            ->assertJsonPath('days', 14)
            ->assertJsonPath('trends.projects.current', 3)
            ->assertJsonPath('trends.users.previous', 1) // the freelancer signed up 20 days ago
            ->assertJsonStructure(['series' => [['date', 'users', 'projects', 'proposals', 'revenue', 'deposits']], 'topCategories', 'recent' => ['users', 'projects']]);

        $this->getJson('/api/admin/overview?days=90')->assertOk()->assertJsonCount(90, 'series')->assertJsonPath('days', 90);
        $this->getJson('/api/admin/overview?days=5')->assertJsonValidationErrors('days');

        $this->as(User::factory()->create())->getJson('/api/admin/overview')->assertForbidden();
    }

    public function test_users_can_be_searched_filtered_and_opened(): void
    {
        $sara = User::factory()->create(['first_name' => 'Sara', 'last_name' => 'Mahmoud', 'email' => 'sara@example.com', 'kyc_status' => 'approved']);
        $omar = User::factory()->create(['first_name' => 'Omar', 'last_name' => 'Khaled', 'suspended_at' => now(), 'suspension_reason' => 'Spam']);
        $this->fund($sara, 75);
        Project::factory()->count(2)->create(['user_id' => $sara->id]);
        Proposal::factory()->create(['user_id' => $sara->id]);

        $names = fn (string $query) => array_column($this->as($this->admin)->getJson("/api/admin/users?{$query}")->assertOk()->json('data'), 'fullName');

        $this->assertContains('Sara Mahmoud', $names('q=mahmoud'));
        $this->assertSame(['Sara Mahmoud'], $names('q=sara@example'));
        $this->assertSame(['Omar Khaled'], $names('status=suspended'));
        $this->assertNotContains('Omar Khaled', $names('status=active'));
        $this->assertSame(['Sara Mahmoud'], $names('kyc=approved'));
        $this->as($this->admin)->getJson('/api/admin/users?status=weird')->assertStatus(422);
        $this->as($sara)->getJson('/api/admin/users')->assertForbidden();

        $this->as($this->admin)->getJson("/api/admin/users/{$sara->id}")->assertOk()
            ->assertJsonPath('data.email', 'sara@example.com')
            ->assertJsonPath('data.kycStatus', 'approved')
            ->assertJsonPath('data.stats.projects', 2)
            ->assertJsonPath('data.stats.offers', 1)
            ->assertJsonPath('data.stats.openDisputes', 0)
            ->assertJsonPath('data.accountType', 'client')
            ->assertJsonPath('wallet.0.available', 75);
        $this->as($this->admin)->getJson("/api/admin/users/{$omar->id}")->assertJsonPath('data.suspensionReason', 'Spam');
        $this->as($this->admin)->getJson('/api/admin/users/9999')->assertNotFound();
    }

    public function test_suspending_locks_the_user_out_and_ends_their_sessions(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('web')->plainTextToken;
        $this->as($this->admin)->postJson("/api/admin/users/{$user->id}/suspend", [])->assertJsonValidationErrors('reason');
        $this->as(User::factory()->create())->postJson("/api/admin/users/{$user->id}/suspend", ['reason' => 'Because'])->assertForbidden();

        $this->as($this->admin)->postJson("/api/admin/users/{$user->id}/suspend", ['reason' => 'Fake profile'])
            ->assertOk()->assertJsonPath('data.suspensionReason', 'Fake profile')->assertJsonPath('data.suspendedAt', fn ($at) => $at !== null);

        $this->assertSame(0, $user->tokens()->count(), 'their sessions end');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
        $this->as($user->fresh())->getJson('/api/wallet')->assertForbidden()->assertJsonPath('code', 'account_suspended');

        $this->as($this->admin)->postJson("/api/admin/users/{$user->id}/unsuspend")->assertOk()->assertJsonPath('data.suspendedAt', null);
        $this->as($user->fresh())->getJson('/api/wallet')->assertOk();

        $other = User::factory()->create(['is_admin' => true]);
        $this->as($this->admin)->postJson("/api/admin/users/{$other->id}/suspend", ['reason' => 'Test'])
            ->assertStatus(422)->assertJsonPath('code', 'cannot_suspend_admin');
    }

    public function test_an_admin_can_adjust_a_wallet_and_it_is_recorded(): void
    {
        $user = User::factory()->create();
        $adjust = fn (array $body) => $this->as($this->admin)->postJson("/api/admin/users/{$user->id}/adjust", $body);

        $adjust(['amount' => 25.5, 'currency' => 'USD', 'reason' => 'Compensation for a failed payment'])
            ->assertOk()->assertJsonPath('data.0.available', 25.5);
        $adjust(['amount' => -10, 'currency' => 'USD', 'reason' => 'Correction of the earlier credit'])->assertOk()->assertJsonPath('data.0.available', 15.5);
        $adjust(['amount' => -100, 'currency' => 'USD', 'reason' => 'Trying to overdraw the wallet'])->assertStatus(422)->assertJsonPath('code', 'insufficient_funds');

        $adjust([])->assertJsonValidationErrors(['amount', 'currency', 'reason']);
        $adjust(['amount' => 0, 'currency' => 'USD', 'reason' => 'Nothing at all'])->assertJsonValidationErrors('amount');
        $adjust(['amount' => 5, 'currency' => 'USD', 'reason' => 'x'])->assertJsonValidationErrors('reason');
        $this->as($user)->postJson("/api/admin/users/{$user->id}/adjust", ['amount' => 1000, 'currency' => 'USD', 'reason' => 'Giving myself money'])->assertForbidden();

        $this->assertSame(15.5, $this->balance($user));
        $this->assertBooksBalance();
        $ledger = $this->as($this->admin)->getJson('/api/admin/ledger/transactions?type=adjustment')->assertJsonPath('meta.total', 2);
        $this->assertSame('Compensation for a failed payment', $ledger->json('data.1.description'));
        $this->assertSame($this->admin->id, LedgerTransaction::where('type', 'adjustment')->first()->created_by);
        $this->as($user)->getJson('/api/wallet/transactions')->assertJsonPath('data.1.label', 'Adjustment by support');
    }

    public function test_projects_can_be_listed_closed_and_featured(): void
    {
        $owner = User::factory()->create();
        $open = Project::factory()->create(['user_id' => $owner->id, 'title' => 'Wooden door design']);
        $closed = Project::factory()->create(['status' => Project::STATUS_CLOSED, 'title' => 'Old job']);
        $offer = Proposal::factory()->create(['project_id' => $open->id]);

        $this->as($this->admin)->getJson('/api/admin/projects')->assertJsonPath('meta.total', 2);
        $this->as($this->admin)->getJson('/api/admin/projects?status=closed')->assertJsonPath('data.0.id', $closed->id);
        $this->as($this->admin)->getJson('/api/admin/projects?q=door')->assertJsonPath('data.0.proposalsCount', 1);
        $this->as($owner)->getJson('/api/admin/projects')->assertForbidden();

        $this->as($this->admin)->postJson("/api/admin/projects/{$open->id}/feature", ['days' => 7])->assertOk();
        $this->assertTrue($open->fresh()->is_featured);
        $this->assertEqualsWithDelta(7, now()->diffInDays($open->fresh()->featured_until, false), 1);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/home')->assertJsonPath('distinctiveProjects.0.id', $open->id);

        $this->as($this->admin)->postJson("/api/admin/projects/{$open->id}/feature", ['days' => 0])->assertJsonValidationErrors('days');
        $this->as($this->admin)->postJson("/api/admin/projects/{$open->id}/unfeature")->assertOk();
        $this->assertFalse($open->fresh()->is_featured);

        $this->as($this->admin)->postJson("/api/admin/projects/{$open->id}/close")->assertOk()->assertJsonPath('data.status', 'closed');
        $this->assertSame('declined', $offer->fresh()->status);
        $this->as($this->admin)->postJson("/api/admin/projects/{$closed->id}/close")->assertStatus(422)->assertJsonPath('code', 'project_not_open');
    }

    public function test_profiles_can_be_featured_on_the_homepage(): void
    {
        $profile = Profile::factory()->create();

        $this->as($this->admin)->postJson("/api/admin/profiles/{$profile->id}/feature", ['days' => 30])
            ->assertOk()->assertJsonPath('isFeatured', true)->assertJsonPath('featuredUntil', fn ($d) => $d !== null);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/home')->assertJsonPath('featuredProfiles.0.id', $profile->id);

        $this->as($this->admin)->postJson("/api/admin/profiles/{$profile->id}/feature", ['days' => 0])->assertOk()->assertJsonPath('isFeatured', false);
    }

    private function as(User $user): static
    {
        return $this->actingAs($user, 'sanctum');
    }
}
