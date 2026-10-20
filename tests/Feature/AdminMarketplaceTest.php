<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminExportController;
use App\Models\Account;
use App\Models\Category;
use App\Models\Deposit;
use App\Models\Escrow;
use App\Models\Milestone;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Review;
use App\Models\Team;
use App\Models\User;
use App\Support\RuntimeConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HandlesMoney;
use Tests\TestCase;

class AdminMarketplaceTest extends TestCase
{
    use HandlesMoney, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    public function test_drafts_stay_private_until_published(): void
    {
        $owner = User::factory()->create();
        $category = Category::factory()->create(['type' => 'project']);

        $id = $this->as($owner)->postJson('/api/projects', [
            'type' => 'project', 'categoryId' => $category->id, 'title' => 'Logo for a bakery', 'description' => 'A friendly logo for our new bakery in Riyadh.',
            'budgetMin' => 100, 'budgetMax' => 300, 'draft' => true,
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/projects/{$id}")->assertNotFound();
        $this->assertSame(0, count($this->getJson('/api/projects')->json('data')));

        $this->as($owner)->getJson("/api/projects/{$id}")->assertOk();
        $this->as($owner)->getJson('/api/me/projects?status=draft')->assertJsonCount(1, 'data');
        $this->as($owner)->postJson("/api/projects/{$id}/publish")->assertOk()->assertJsonPath('data.status', 'open');
        $this->as($owner)->postJson("/api/projects/{$id}/publish")->assertJsonPath('code', 'not_a_draft');
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/projects/{$id}")->assertOk();
    }

    public function test_admins_hide_reactivate_and_inspect_projects(): void
    {
        $client = User::factory()->create();
        $freelancer = User::factory()->create(['first_name' => 'Fay']);
        $open = Project::factory()->create(['title' => 'Website redesign']);
        $hired = $this->hiredProject($client, $freelancer, 500);

        $this->as($this->admin)->postJson("/api/admin/projects/{$open->id}/hide", ['reason' => 'Asks to pay outside the platform'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/projects/{$open->id}")->assertNotFound();
        $this->assertSame([], $this->getJson('/api/projects')->json('data'));
        $this->as($open->user)->getJson("/api/projects/{$open->id}")->assertOk();
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $open->user_id]);

        $this->as($this->admin)->getJson('/api/admin/projects?hidden=1')->assertJsonCount(1, 'data');
        $this->as($this->admin)->postJson("/api/admin/projects/{$open->id}/reactivate")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/projects/{$open->id}")->assertOk();

        $this->as($this->admin)->getJson("/api/admin/projects/{$hired->id}")->assertOk()
            ->assertJsonPath('data.proposals.0.user.fullName', $freelancer->full_name)
            ->assertJsonPath('data.proposals.0.status', 'accepted')
            ->assertJsonPath('data.escrow', null)
            ->assertJsonPath('data.matches', []);
        $this->as($this->admin)->getJson("/api/admin/projects?userId={$client->id}")->assertJsonCount(1, 'data');
    }

    public function test_offers_are_listed_with_their_project_and_counts(): void
    {
        $project = Project::factory()->create(['title' => 'Instagram campaign', 'type' => 'influencer_request']);
        Proposal::factory()->create(['project_id' => $project->id, 'status' => 'pending', 'message' => 'I can post three reels next week.']);
        Proposal::factory()->create(['status' => 'declined']);

        $this->as($this->admin)->getJson('/api/admin/proposals')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('counts.pending', 1);
        $this->as($this->admin)->getJson('/api/admin/proposals?type=influencer_request')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.project.title', 'Instagram campaign');
        $this->as($this->admin)->getJson('/api/admin/proposals?q=reels')->assertJsonCount(1, 'data');
        $this->as($this->admin)->getJson('/api/admin/proposals?status=declined')->assertJsonCount(1, 'data');
    }

    public function test_hiding_a_review_takes_it_out_of_the_profile_and_average(): void
    {
        $freelancer = User::factory()->create();
        Profile::factory()->create(['user_id' => $freelancer->id]);
        $project = Project::factory()->create(['status' => 'completed']);
        $bad = Review::create(['project_id' => $project->id, 'reviewer_id' => User::factory()->create()->id, 'reviewee_id' => $freelancer->id, 'rating' => 1, 'comment' => 'Spam review']);
        Review::create(['project_id' => $project->id, 'reviewer_id' => User::factory()->create()->id, 'reviewee_id' => $freelancer->id, 'rating' => 5]);
        Review::refreshStats($freelancer->id);
        $this->assertEquals(3.0, $freelancer->profile->fresh()->rating);

        $this->as($this->admin)->postJson("/api/admin/reviews/{$bad->id}/hide", ['reason' => 'Fake review'])->assertOk()->assertJsonPath('data.hidden', true);
        $this->assertEquals(5.0, $freelancer->profile->fresh()->rating);
        $this->assertSame(1, $freelancer->profile->fresh()->reviews_count);
        $this->getJson("/api/users/{$freelancer->id}/reviews")->assertJsonCount(1, 'data');

        $this->as($this->admin)->getJson('/api/admin/reviews?state=hidden')->assertJsonCount(1, 'data')->assertJsonPath('summary.hidden', 1);
        $this->as($this->admin)->postJson("/api/admin/reviews/{$bad->id}/unhide")->assertOk();
        $this->assertEquals(3.0, $freelancer->profile->fresh()->rating);
    }

    public function test_teams_are_listed_and_inspected(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $team = Team::create(['owner_id' => $owner->id, 'name' => 'Pixel Crew', 'status' => Team::ACTIVE]);
        $team->members()->create(['user_id' => $owner->id, 'share_bp' => 6000, 'status' => 'accepted', 'is_lead' => true]);
        $team->members()->create(['user_id' => $member->id, 'share_bp' => 4000, 'status' => 'accepted', 'role_title' => 'Designer']);

        $this->as($this->admin)->getJson('/api/admin/teams')->assertOk()->assertJsonPath('data.0.name', 'Pixel Crew')->assertJsonPath('data.0.members', 2);
        $this->as($this->admin)->getJson("/api/admin/teams/{$team->id}")->assertOk()
            ->assertJsonPath('data.members.1.roleTitle', 'Designer')
            ->assertJsonPath('data.members.1.sharePercent', 40);
        $this->as($this->admin)->postJson("/api/admin/teams/{$team->id}/disband", ['reason' => 'Abuse'])->assertOk();
        $this->assertSame(Team::DISBANDED, $team->fresh()->status);
    }

    public function test_contracts_are_listed_and_an_admin_can_cancel_one(): void
    {
        $client = User::factory()->create();
        $freelancer = User::factory()->create();
        $project = $this->hiredProject($client, $freelancer, 1000);
        $this->fund($client, 1000);
        $this->as($client)->postJson("/api/projects/{$project->id}/escrow", ['milestones' => [['title' => 'Design', 'amount' => 600], ['title' => 'Build', 'amount' => 400]]])->assertCreated();
        $first = Milestone::orderBy('position')->first();
        $this->as($client)->postJson("/api/milestones/{$first->id}/fund")->assertOk();
        $escrow = Escrow::first();

        $this->as($this->admin)->getJson('/api/admin/escrows?holding=1')->assertOk()
            ->assertJsonPath('data.0.held', 600)
            ->assertJsonPath('totals.0.held', 600);
        $this->as($this->admin)->getJson("/api/admin/escrows/{$escrow->id}")->assertOk()->assertJsonCount(2, 'data.milestones');

        $this->as($this->admin)->postJson("/api/admin/escrows/{$escrow->id}/cancel", ['reason' => 'Both sides asked to cancel'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(1000.0, $this->balance($client), 'the funded milestone went back to the client');
        $this->assertSame(0.0, $this->balance(Account::ESCROW));
        $this->assertSame(Project::STATUS_CLOSED, $project->fresh()->status);
        $this->assertBooksBalance();
        $this->as($this->admin)->postJson("/api/admin/escrows/{$escrow->id}/cancel", ['reason' => 'Again please'])->assertJsonPath('code', 'escrow_closed');
    }

    public function test_money_lists_and_ledger_filters(): void
    {
        $user = User::factory()->create();
        Deposit::create(['user_id' => $user->id, 'amount_cents' => 5000, 'currency' => 'USD', 'method' => 'card', 'status' => 'failed', 'provider' => 'moyasar', 'provider_ref' => 'pay_abc123', 'failure_reason' => 'Declined']);
        $this->fund($user, 20);

        $this->as($this->admin)->getJson('/api/admin/deposits?status=failed')->assertOk()->assertJsonPath('data.0.providerRef', 'pay_abc123')->assertJsonPath('counts.failed', 1);
        $this->as($this->admin)->getJson('/api/admin/deposits?reference=abc1')->assertJsonCount(1, 'data');
        $this->as($this->admin)->getJson('/api/admin/subscriptions')->assertOk();
        $this->as($this->admin)->getJson('/api/admin/promotions')->assertOk()->assertJsonPath('summary.total', 0);

        $this->as($this->admin)->getJson('/api/admin/ledger/transactions?from='.now()->toDateString())->assertJsonCount(1, 'data');
        $this->as($this->admin)->getJson('/api/admin/ledger/transactions?to='.now()->subDays(2)->toDateString())->assertJsonCount(0, 'data');
        $id = $this->as($this->admin)->getJson('/api/admin/ledger/transactions')->json('data.0.id');
        $this->as($this->admin)->getJson("/api/admin/ledger/transactions?reference={$id}")->assertJsonCount(1, 'data');
    }

    public function test_promotion_prices_are_edited_from_the_panel(): void
    {
        $this->as($this->admin)->putJson('/api/admin/promotions/prices', ['prices' => ['profile' => ['USD' => 9], 'project' => ['USD' => 12]], 'points' => 50])->assertOk()
            ->assertJsonPath('data.prices.profile.USD', 9)
            ->assertJsonPath('data.points', 50);
        // A fresh process (another request, a queue worker) picks the saved prices up from the settings.
        config(['marketplace.promotions.prices' => ['profile' => ['USD' => 2], 'project' => ['USD' => 3]]]);
        RuntimeConfig::apply();
        $this->assertEquals(9, config('marketplace.promotions.prices.profile.USD'));
        $this->assertSame(50, config('marketplace.promotions.points'));
    }

    public function test_exports_download_csv_files(): void
    {
        User::factory()->create(['first_name' => 'سارة', 'last_name' => 'Ahmed']);

        $csv = $this->as($this->admin)->get('/api/admin/export/users')->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('سارة Ahmed', $csv);
        $this->assertStringContainsString('id,name,username', $csv);

        foreach (array_keys(AdminExportController::DATASETS) as $dataset) {
            $this->as($this->admin)->get("/api/admin/export/{$dataset}")->assertOk();
        }
        $this->as($this->admin)->get('/api/admin/export/secrets')->assertNotFound();
    }

    public function test_the_analytics_and_dashboard_cover_every_area(): void
    {
        $this->hiredProject(User::factory()->create(), User::factory()->create());

        $this->as($this->admin)->getJson('/api/admin/analytics')->assertOk()
            ->assertJsonStructure(['data' => ['users' => ['byType'], 'projects' => ['acceptanceRate'], 'money', 'disputes' => ['avgHoursToResolve'], 'kyc', 'reviews', 'services', 'rewards', 'moderation']])
            ->assertJsonPath('data.projects.proposals', 1);
        $this->as($this->admin)->getJson('/api/admin/overview')->assertOk()
            ->assertJsonPath('influencerRequests.open', 0)
            ->assertJsonStructure(['engagement' => ['activeSubscriptions', 'activePromotions', 'pointsIssued', 'medalsAwarded']]);
    }

    private function as(User $user): static
    {
        return $this->actingAs($user, 'sanctum');
    }
}
