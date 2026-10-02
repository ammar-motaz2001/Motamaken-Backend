<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\City;
use App\Models\Country;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProposalsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $freelancer;

    private User $stranger;

    private Category $web;

    private Category $instagram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->web = Category::factory()->create(['slug' => 'web']);
        $this->instagram = Category::factory()->influencer()->create(['slug' => 'instagram']);
        $this->owner = User::factory()->create(['first_name' => 'Owen', 'last_name' => 'Owner']);
        $this->freelancer = User::factory()->create(['first_name' => 'Fatima', 'last_name' => 'Free']);
        $this->stranger = User::factory()->create();
        Profile::factory()->create(['user_id' => $this->freelancer->id, 'job_title' => 'Designer', 'rating' => 4.8]);
    }

    // ---- posting and managing projects ---------------------------------------------------------------------------

    public function test_posting_a_project_makes_it_public_and_lists_it_under_my_projects(): void
    {
        $egypt = Country::factory()->create();
        $cairo = City::factory()->create(['country_id' => $egypt->id]);

        $response = $this->as($this->owner)->postJson('/api/projects', $this->payload([
            'countryId' => $egypt->id, 'cityId' => $cairo->id, 'currency' => 'SAR',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.owner.id', $this->owner->id)
            ->assertJsonPath('data.budget', ['min' => 100, 'max' => 300, 'currency' => 'SAR'])
            ->assertJsonPath('data.category.slug', 'web');

        $id = $response->json('data.id');
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/projects/{$id}")->assertOk();
        $this->getJson('/api/projects?category=web')->assertJsonPath('meta.total', 1);

        $this->as($this->owner)->getJson('/api/me/projects')->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.proposalsCount', 0);
        $this->as($this->stranger)->getJson('/api/me/projects')->assertJsonPath('data', []);
    }

    public function test_an_influencer_request_needs_a_platform_and_a_project_needs_a_project_category(): void
    {
        $this->as($this->owner)->postJson('/api/projects', $this->payload(['type' => 'influencer_request', 'categoryId' => $this->instagram->id]))
            ->assertCreated()->assertJsonPath('data.type', 'influencer_request');

        $this->as($this->owner)->postJson('/api/projects', $this->payload(['type' => 'influencer_request']))
            ->assertStatus(422)->assertJsonValidationErrors('categoryId');
        $this->as($this->owner)->postJson('/api/projects', $this->payload(['categoryId' => $this->instagram->id]))
            ->assertStatus(422)->assertJsonValidationErrors('categoryId');
    }

    public function test_project_validation(): void
    {
        $post = fn (array $override) => $this->as($this->owner)->postJson('/api/projects', $this->payload($override));
        $other = City::factory()->create();

        $this->as($this->owner)->postJson('/api/projects', [])->assertStatus(422)
            ->assertJsonValidationErrors(['type', 'categoryId', 'title', 'description', 'budgetMin', 'budgetMax']);
        $post(['title' => 'abc'])->assertJsonValidationErrors('title');
        $post(['description' => 'too short'])->assertJsonValidationErrors('description');
        $post(['budgetMin' => 0])->assertJsonValidationErrors('budgetMin');
        $post(['budgetMin' => 500, 'budgetMax' => 100])->assertJsonValidationErrors('budgetMax');
        $post(['currency' => 'XXX'])->assertJsonValidationErrors('currency');
        $post(['type' => 'job'])->assertJsonValidationErrors('type');
        $post(['categoryId' => 999])->assertJsonValidationErrors('categoryId');
        $post(['cityId' => $other->id])->assertJsonValidationErrors('countryId');
        $post(['countryId' => Country::factory()->create()->id, 'cityId' => $other->id])->assertJsonValidationErrors('cityId');

        $this->assertDatabaseCount('projects', 0);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/projects', $this->payload())->assertUnauthorized();
    }

    public function test_only_the_owner_can_edit_and_only_while_open(): void
    {
        $project = $this->project(['budget_min' => 100, 'budget_max' => 300]);

        $this->as($this->owner)->patchJson("/api/projects/{$project->id}", ['title' => 'A better title', 'budgetMax' => 900])
            ->assertOk()->assertJsonPath('data.title', 'A better title')->assertJsonPath('data.budget.max', 900)
            ->assertJsonPath('data.budget.min', 100);

        $this->as($this->stranger)->patchJson("/api/projects/{$project->id}", ['title' => 'Hijacked'])->assertForbidden();
        $this->as($this->owner)->patchJson("/api/projects/{$project->id}", ['type' => 'influencer_request'])->assertStatus(422);
        // The merged range is checked too: lowering the max below the stored min.
        $this->as($this->owner)->patchJson("/api/projects/{$project->id}", ['budgetMax' => 50])->assertJsonValidationErrors('budgetMax');
        $this->as($this->owner)->patchJson("/api/projects/{$project->id}", ['categoryId' => $this->instagram->id])->assertJsonValidationErrors('categoryId');

        $project->update(['status' => Project::STATUS_IN_PROGRESS]);
        $this->as($this->owner)->patchJson("/api/projects/{$project->id}", ['title' => 'Too late'])
            ->assertStatus(422)->assertJsonPath('code', 'project_not_editable');
        $this->assertSame('A better title', $project->fresh()->title);
    }

    public function test_changing_the_country_clears_the_old_city(): void
    {
        $egypt = Country::factory()->create();
        $cairo = City::factory()->create(['country_id' => $egypt->id]);
        $project = $this->project(['country_id' => $egypt->id, 'city_id' => $cairo->id]);

        $this->as($this->owner)->patchJson("/api/projects/{$project->id}", ['countryId' => Country::factory()->create()->id])->assertOk();

        $this->assertNull($project->fresh()->city_id);
    }

    public function test_a_project_can_be_deleted_only_without_offers(): void
    {
        $free = $this->project();
        $busy = $this->project();
        Proposal::factory()->create(['project_id' => $busy->id]);

        $this->as($this->stranger)->deleteJson("/api/projects/{$free->id}")->assertForbidden();
        $this->as($this->owner)->deleteJson("/api/projects/{$busy->id}")->assertStatus(422)->assertJsonPath('code', 'project_has_proposals');
        $this->as($this->owner)->deleteJson("/api/projects/{$free->id}")->assertOk()->assertExactJson(['success' => true]);

        $this->assertModelMissing($free);
        $this->assertModelExists($busy);
    }

    // ---- sending offers -------------------------------------------------------------------------------------------

    public function test_a_freelancer_sends_an_offer_and_the_owner_is_notified(): void
    {
        $project = $this->project();

        $this->as($this->freelancer)->postJson("/api/projects/{$project->id}/proposals", $this->offer(['amount' => 149.5]))
            ->assertCreated()
            ->assertJsonPath('data.amount', 149.5)
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.freelancer.fullName', 'Fatima Free')
            ->assertJsonPath('data.freelancer.jobTitle', 'Designer')
            ->assertJsonPath('data.freelancer.rating', 4.8);

        $this->as($this->owner)->getJson('/api/me/summary')->assertJsonPath('unreadNotifications', 1);
        $this->as($this->owner)->getJson('/api/notifications')
            ->assertJsonPath('data.0.type', 'proposal.received')
            ->assertJsonPath('data.0.message', "Fatima Free sent an offer on \"{$project->title}\".")
            ->assertJsonPath('data.0.projectId', $project->id);
        $this->as($this->owner)->getJson('/api/notifications', ['Accept-Language' => 'ar'])
            ->assertJsonPath('data.0.message', "أرسل Fatima Free عرضاً على \"{$project->title}\".");
    }

    public function test_offer_rules_come_with_a_code_the_frontend_can_act_on(): void
    {
        $project = $this->project();
        $offer = fn (User $who, ?Project $on = null) => $this->as($who)->postJson('/api/projects/'.($on ?? $project)->id.'/proposals', $this->offer());

        $offer($this->owner)->assertStatus(422)->assertJsonPath('code', 'own_project');
        $offer($this->stranger)->assertStatus(422)->assertJsonPath('code', 'profile_required');

        $influencer = User::factory()->create();
        Profile::factory()->influencer()->create(['user_id' => $influencer->id]);
        $offer($influencer)->assertStatus(422)->assertJsonPath('code', 'profile_type_mismatch');
        $request = $this->project(['type' => 'influencer_request', 'category_id' => $this->instagram->id]);
        $offer($this->freelancer, $request)->assertStatus(422)->assertJsonPath('code', 'profile_type_mismatch');
        $offer($influencer, $request)->assertCreated();

        $offer($this->freelancer)->assertCreated();
        $offer($this->freelancer)->assertStatus(422)->assertJsonPath('code', 'already_offered');

        $closed = $this->project(['status' => Project::STATUS_CLOSED]);
        $offer($this->freelancer, $closed)->assertStatus(422)->assertJsonPath('code', 'project_not_open');

        $this->as($this->freelancer)->postJson("/api/projects/{$project->id}/proposals", $this->offer([], 'ar'), ['Accept-Language' => 'ar']);
        $this->as($this->stranger)->postJson("/api/projects/{$project->id}/proposals", $this->offer(), ['Accept-Language' => 'ar'])
            ->assertJsonPath('message', 'أضف ملفك الشخصي قبل إرسال العروض.');
    }

    public function test_offer_validation(): void
    {
        $project = $this->project();
        $post = fn (array $data) => $this->as($this->freelancer)->postJson("/api/projects/{$project->id}/proposals", $data);

        $post([])->assertStatus(422)->assertJsonValidationErrors(['amount', 'deliveryDays', 'message']);
        $post($this->offer(['amount' => 0]))->assertJsonValidationErrors('amount');
        $post($this->offer(['amount' => 10.555]))->assertJsonValidationErrors('amount');
        $post($this->offer(['deliveryDays' => 0]))->assertJsonValidationErrors('deliveryDays');
        $post($this->offer(['deliveryDays' => 366]))->assertJsonValidationErrors('deliveryDays');
        $post($this->offer(['message' => 'short']))->assertJsonValidationErrors('message');

        $this->assertDatabaseCount('proposals', 0);
        $this->as($this->freelancer)->postJson('/api/projects/999/proposals', $this->offer())->assertNotFound();
    }

    public function test_an_offer_can_be_edited_or_withdrawn_only_by_its_sender_and_only_while_pending(): void
    {
        $offer = $this->proposal();

        $this->as($this->freelancer)->patchJson("/api/proposals/{$offer->id}", ['amount' => 90, 'deliveryDays' => 3])
            ->assertOk()->assertJsonPath('data.amount', 90)->assertJsonPath('data.deliveryDays', 3);
        $this->as($this->owner)->patchJson("/api/proposals/{$offer->id}", ['amount' => 1])->assertForbidden();
        $this->as($this->stranger)->postJson("/api/proposals/{$offer->id}/withdraw")->assertForbidden();

        $this->as($this->freelancer)->postJson("/api/proposals/{$offer->id}/withdraw")->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->as($this->freelancer)->patchJson("/api/proposals/{$offer->id}", ['amount' => 80])
            ->assertStatus(422)->assertJsonPath('code', 'proposal_not_pending');
        $this->as($this->freelancer)->postJson("/api/proposals/{$offer->id}/withdraw")->assertStatus(422);
        $this->as($this->owner)->postJson("/api/proposals/{$offer->id}/accept")->assertStatus(422)->assertJsonPath('code', 'proposal_not_pending');
    }

    // ---- answering offers -----------------------------------------------------------------------------------------

    public function test_accepting_an_offer_starts_the_project_and_declines_the_others(): void
    {
        $project = $this->project();
        $winner = $this->proposal($project);
        $loser = $this->proposal($project, $this->freelancerWithProfile());
        $withdrawn = $this->proposal($project, $this->freelancerWithProfile(), ['status' => Proposal::STATUS_WITHDRAWN]);

        $this->as($this->stranger)->postJson("/api/proposals/{$winner->id}/accept")->assertForbidden();
        $this->as($this->freelancer)->postJson("/api/proposals/{$winner->id}/accept")->assertForbidden();

        $this->as($this->owner)->postJson("/api/proposals/{$winner->id}/accept")->assertOk()->assertJsonPath('data.status', 'accepted');

        $this->assertSame(Project::STATUS_IN_PROGRESS, $project->fresh()->status);
        $this->assertSame($winner->id, $project->fresh()->accepted_proposal_id);
        $this->assertSame('declined', $loser->fresh()->status);
        $this->assertSame('withdrawn', $withdrawn->fresh()->status, 'withdrawn offers are left alone');

        $this->assertSame(['proposal.accepted'], $this->freelancer->notifications->pluck('data.type')->all());
        $this->assertSame(['proposal.declined'], $loser->user->notifications->pluck('data.type')->all());
        $this->assertCount(0, $withdrawn->user->notifications);

        $this->as($this->freelancer)->getJson('/api/notifications')
            ->assertJsonPath('data.0.message', 'Owen Owner accepted your offer on "'.$project->title.'".');

        // No longer public, and no second offer can be accepted.
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/projects/{$project->id}")->assertNotFound();
        $this->as($this->owner)->postJson("/api/proposals/{$loser->id}/accept")->assertStatus(422);
    }

    public function test_declining_an_offer(): void
    {
        $offer = $this->proposal();

        $this->as($this->stranger)->postJson("/api/proposals/{$offer->id}/decline")->assertForbidden();
        $this->as($this->owner)->postJson("/api/proposals/{$offer->id}/decline")->assertOk()->assertJsonPath('data.status', 'declined');
        $this->as($this->owner)->postJson("/api/proposals/{$offer->id}/decline")->assertStatus(422);

        $this->assertTrue($offer->project->fresh()->isOpen(), 'the project stays open for other offers');
        $this->as($this->freelancer)->getJson('/api/notifications')
            ->assertJsonPath('data.0.message', "Your offer on \"{$offer->project->title}\" was declined.");
    }

    public function test_closing_a_project_declines_pending_offers(): void
    {
        $project = $this->project();
        $offer = $this->proposal($project);

        $this->as($this->stranger)->postJson("/api/projects/{$project->id}/close")->assertForbidden();
        $this->as($this->owner)->postJson("/api/projects/{$project->id}/close")->assertOk()->assertJsonPath('data.status', 'closed');

        $this->assertSame('declined', $offer->fresh()->status);
        $this->assertCount(1, $this->freelancer->notifications);
        $this->as($this->owner)->postJson("/api/projects/{$project->id}/close")->assertStatus(422)->assertJsonPath('code', 'project_not_open');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/projects')->assertJsonPath('meta.total', 0);
    }

    public function test_a_project_is_completed_only_when_in_progress(): void
    {
        $project = $this->project();

        $this->as($this->owner)->postJson("/api/projects/{$project->id}/complete")
            ->assertStatus(422)->assertJsonPath('code', 'project_not_in_progress');

        $this->as($this->owner)->postJson('/api/proposals/'.$this->proposal($project)->id.'/accept')->assertOk();
        $this->as($this->stranger)->postJson("/api/projects/{$project->id}/complete")->assertForbidden();
        $this->as($this->owner)->postJson("/api/projects/{$project->id}/complete")->assertOk()->assertJsonPath('data.status', 'completed');
        $this->as($this->owner)->postJson("/api/projects/{$project->id}/close")->assertStatus(422);
    }

    // ---- reading ---------------------------------------------------------------------------------------------------

    public function test_the_owner_reads_the_offers_and_the_sender_reads_my_offers(): void
    {
        $project = $this->project();
        $mine = $this->proposal($project);
        $second = $this->proposal($project, $this->freelancerWithProfile(), ['status' => Proposal::STATUS_DECLINED]);
        $elsewhere = $this->proposal(Project::factory()->create(['user_id' => $this->stranger->id, 'category_id' => $this->web->id]));

        $this->as($this->owner)->getJson("/api/projects/{$project->id}/proposals")
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.freelancer.jobTitle', fn ($t) => $t !== null);
        $this->as($this->owner)->getJson("/api/projects/{$project->id}/proposals?status=pending")
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $mine->id);
        $this->as($this->owner)->getJson("/api/projects/{$project->id}/proposals?status=bogus")->assertStatus(422);
        $this->as($this->stranger)->getJson("/api/projects/{$project->id}/proposals")->assertForbidden();
        $this->as($this->freelancer)->getJson("/api/projects/{$project->id}/proposals")->assertForbidden();

        // The freelancer made two offers (newest first); the other freelancer's offer is not listed.
        $this->as($this->freelancer)->getJson('/api/me/proposals')
            ->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $elsewhere->id)
            ->assertJsonPath('data.1.id', $mine->id)
            ->assertJsonPath('data.1.project.title', $project->title)
            ->assertJsonPath('data.1.project.owner.fullName', 'Owen Owner');
        $this->as($this->freelancer)->getJson('/api/me/proposals?status=declined')->assertJsonPath('data', []);

        $this->as($this->owner)->getJson("/api/proposals/{$mine->id}")->assertOk();
        $this->as($this->freelancer)->getJson("/api/proposals/{$mine->id}")->assertOk();
        $this->as($this->stranger)->getJson("/api/proposals/{$mine->id}")->assertForbidden();
        $this->as($this->owner)->getJson("/api/proposals/{$elsewhere->id}")->assertForbidden();

        $this->as($this->owner)->getJson('/api/me/projects')->assertJsonPath('data.0.proposalsCount', 2)
            ->assertJsonCount(1, 'data');
        $this->as($this->owner)->getJson('/api/me/projects?status=closed')->assertJsonPath('data', []);
        $this->assertNotNull($second);
    }

    public function test_project_page_visibility_and_my_offer(): void
    {
        $project = $this->project();
        $offer = $this->proposal($project);

        // Open: public. Only a logged-in visitor gets `myProposal`.
        $this->getJson("/api/projects/{$project->id}")->assertOk()->assertJsonMissingPath('data.myProposal');
        $this->as($this->freelancer)->getJson("/api/projects/{$project->id}")->assertJsonPath('data.myProposal.id', $offer->id);
        $this->as($this->stranger)->getJson("/api/projects/{$project->id}")->assertJsonPath('data.myProposal', null);
        $this->as($this->owner)->getJson("/api/projects/{$project->id}")->assertJsonMissingPath('data.myProposal');

        $project->update(['status' => Project::STATUS_IN_PROGRESS]);
        $this->as($this->owner)->getJson("/api/projects/{$project->id}")->assertOk();
        $this->as($this->freelancer)->getJson("/api/projects/{$project->id}")->assertOk();
        $this->as($this->stranger)->getJson("/api/projects/{$project->id}")->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/projects/{$project->id}")->assertNotFound();
    }

    // ---- profile ---------------------------------------------------------------------------------------------------

    public function test_adding_a_profile_unlocks_offers(): void
    {
        $project = $this->project();
        $data = ['type' => 'freelancer', 'jobTitle' => 'Web Developer', 'bio' => 'Ten years of Laravel.'];

        $this->as($this->stranger)->getJson('/api/me/profile')->assertNotFound();
        $this->as($this->stranger)->postJson("/api/projects/{$project->id}/proposals", $this->offer())->assertJsonPath('code', 'profile_required');

        $this->as($this->stranger)->putJson('/api/me/profile', $data)
            ->assertCreated()->assertJsonPath('data.jobTitle', 'Web Developer')->assertJsonPath('data.isFeatured', false);
        $this->as($this->stranger)->postJson("/api/projects/{$project->id}/proposals", $this->offer())->assertCreated();

        // Saving again updates the same profile; users cannot promote themselves.
        $this->as($this->stranger)->putJson('/api/me/profile', [...$data, 'jobTitle' => 'Backend Developer', 'isFeatured' => true, 'rating' => 5])
            ->assertOk()->assertJsonPath('data.jobTitle', 'Backend Developer')->assertJsonPath('data.isFeatured', false);
        $this->assertSame(1, Profile::where('user_id', $this->stranger->id)->count());
        $this->as($this->stranger)->getJson('/api/me/profile')->assertOk()->assertJsonPath('data.bio', 'Ten years of Laravel.');
    }

    public function test_profile_validation(): void
    {
        $put = fn (array $data) => $this->as($this->stranger)->putJson('/api/me/profile', $data);

        $put([])->assertStatus(422)->assertJsonValidationErrors(['type', 'jobTitle']);
        $put(['type' => 'admin', 'jobTitle' => 'X Y'])->assertJsonValidationErrors('type');
        $put(['type' => 'freelancer', 'jobTitle' => 'Designer', 'cityId' => City::factory()->create()->id])->assertJsonValidationErrors('countryId');
        $put(['type' => 'freelancer', 'jobTitle' => 'Designer', 'countryId' => Country::factory()->create()->id, 'cityId' => City::factory()->create()->id])
            ->assertJsonValidationErrors('cityId');
    }

    // ---- helpers ---------------------------------------------------------------------------------------------------

    private function as(User $user): static
    {
        return $this->actingAs($user, 'sanctum');
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function payload(array $override = []): array
    {
        return [
            'type' => 'project',
            'categoryId' => $this->web->id,
            'title' => 'Logo design for a wood company',
            'description' => 'We need a clean, modern logo and a small brand guide for print and social media.',
            'budgetMin' => 100,
            'budgetMax' => 300,
            ...$override,
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function offer(array $override = [], string $locale = 'en'): array
    {
        return ['amount' => 150, 'deliveryDays' => 7, 'message' => 'I can deliver this within a week with two rounds of revisions.', ...$override];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function project(array $override = []): Project
    {
        return Project::factory()->create(['user_id' => $this->owner->id, 'category_id' => $this->web->id, ...$override]);
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function proposal(?Project $project = null, ?User $by = null, array $override = []): Proposal
    {
        return Proposal::factory()->create([
            'project_id' => ($project ?? $this->project())->id,
            'user_id' => ($by ?? $this->freelancer)->id,
            ...$override,
        ]);
    }

    private function freelancerWithProfile(): User
    {
        return Profile::factory()->create()->user;
    }
}
