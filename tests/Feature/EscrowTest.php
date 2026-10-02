<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Escrow;
use App\Models\EscrowPayee;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Services\Payments\EscrowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HandlesMoney;
use Tests\TestCase;

class EscrowTest extends TestCase
{
    use HandlesMoney, RefreshDatabase;

    private User $client;

    private User $freelancer;

    private User $stranger;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create(['first_name' => 'Cleo', 'last_name' => 'Client']);
        $this->freelancer = User::factory()->create(['first_name' => 'Fay', 'last_name' => 'Freelancer']);
        $this->stranger = User::factory()->create();
        $this->project = $this->hiredProject($this->client, $this->freelancer, 1000);
    }

    // ---- setting up -----------------------------------------------------------------------------------------------

    public function test_the_client_splits_the_offer_into_milestones(): void
    {
        $this->as($this->client)->postJson("/api/projects/{$this->project->id}/escrow", $this->plan())
            ->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_funding')
            ->assertJsonPath('data.totals', ['total' => 1000, 'held' => 0, 'released' => 0, 'refunded' => 0])
            ->assertJsonPath('data.milestones.0.title', 'Design')
            ->assertJsonPath('data.milestones.0.status', 'pending')
            ->assertJsonPath('data.milestones.1.position', 2)
            ->assertJsonPath('data.payees', [['userId' => $this->freelancer->id, 'fullName' => 'Fay Freelancer', 'avatarUrl' => null, 'sharePercent' => 100, 'isLead' => true]]);

        $this->as($this->freelancer)->getJson('/api/notifications')
            ->assertJsonPath('data.0.type', 'escrow.created')
            ->assertJsonPath('data.0.message', "Cleo Client set up payment protection for \"{$this->project->title}\". Fund the first milestone to start.");
        $this->as($this->freelancer)->getJson("/api/projects/{$this->project->id}/escrow")->assertOk();
        $this->as($this->stranger)->getJson("/api/projects/{$this->project->id}/escrow")->assertForbidden();
    }

    public function test_setting_up_is_refused_when_it_does_not_make_sense(): void
    {
        $post = fn (User $who, array $body, ?Project $on = null) => $this->as($who)->postJson('/api/projects/'.($on ?? $this->project)->id.'/escrow', $body);

        $post($this->freelancer, $this->plan())->assertForbidden();
        $post($this->stranger, $this->plan())->assertForbidden();

        $post($this->client, ['milestones' => [['title' => 'All', 'amount' => 900]]])
            ->assertStatus(422)->assertJsonPath('code', 'milestones_total')->assertJsonPath('message', 'The milestones must add up to the offer: 1000 USD.');
        $post($this->client, ['milestones' => [['title' => 'First', 'amount' => 600], ['title' => 'Second', 'amount' => 400.01]]])->assertJsonPath('code', 'milestones_total');
        $post($this->client, [])->assertJsonValidationErrors('milestones');
        $post($this->client, ['milestones' => [['title' => 'x', 'amount' => -5]]])->assertJsonValidationErrors(['milestones.0.title', 'milestones.0.amount']);
        $post($this->client, ['milestones' => [['title' => 'Late', 'amount' => 1000, 'dueDate' => '2020-01-01']]])->assertJsonValidationErrors('milestones.0.dueDate');

        $open = Project::factory()->create(['user_id' => $this->client->id]);
        $post($this->client, $this->plan(), $open)->assertStatus(422)->assertJsonPath('code', 'escrow_needs_hired');

        $post($this->client, $this->plan())->assertCreated();
        $post($this->client, $this->plan())->assertStatus(422)->assertJsonPath('code', 'escrow_exists');
        $this->assertDatabaseCount('escrows', 1);
    }

    public function test_an_escrow_nothing_was_paid_into_can_be_cancelled_and_redone(): void
    {
        $this->setUpEscrow();

        $this->as($this->freelancer)->postJson("/api/projects/{$this->project->id}/escrow/cancel")->assertForbidden();
        $this->as($this->client)->postJson("/api/projects/{$this->project->id}/escrow/cancel")->assertOk()->assertExactJson(['success' => true]);
        $this->assertDatabaseCount('milestones', 0);

        $this->as($this->client)->postJson("/api/projects/{$this->project->id}/escrow", $this->plan())->assertCreated();

        $this->fund($this->client, 1000);
        $this->as($this->client)->postJson('/api/milestones/'.Milestone::first()->id.'/fund')->assertOk();
        $this->as($this->client)->postJson("/api/projects/{$this->project->id}/escrow/cancel")->assertStatus(422)->assertJsonPath('code', 'escrow_has_funds');
    }

    // ---- funding ----------------------------------------------------------------------------------------------------

    public function test_funding_moves_money_from_the_wallet_into_escrow(): void
    {
        [$design] = $this->setUpEscrow();
        $this->fund($this->client, 700);

        $this->as($this->stranger)->postJson("/api/milestones/{$design->id}/fund")->assertForbidden();
        $this->as($this->freelancer)->postJson("/api/milestones/{$design->id}/fund")->assertForbidden();

        $this->as($this->client)->postJson("/api/milestones/{$design->id}/fund")->assertOk()->assertJsonPath('data.status', 'funded');

        $this->assertSame(300.0, $this->balance($this->client));
        $this->assertSame(400.0, $this->balance(Account::ESCROW));
        $this->assertSame('active', Escrow::first()->status);
        $this->assertBooksBalance();

        $this->as($this->client)->postJson("/api/milestones/{$design->id}/fund")->assertStatus(422)->assertJsonPath('code', 'milestone_not_pending');
        $this->as($this->freelancer)->getJson('/api/notifications')
            ->assertJsonPath('data.0.message', "Cleo Client paid \"Design\" into escrow on \"{$this->project->title}\". You can start working.");

        $this->as($this->client)->getJson('/api/wallet')->assertJsonPath('data.0.available', 300)->assertJsonPath('data.0.inEscrow', 400);
        $this->as($this->freelancer)->getJson('/api/wallet')->assertJsonPath('data.0.incoming', 400)->assertJsonPath('data.0.available', 0);
    }

    public function test_funding_without_enough_money_changes_nothing(): void
    {
        $this->setUpEscrow();
        $this->fund($this->client, 100);

        $this->as($this->client)->postJson('/api/milestones/'.Milestone::first()->id.'/fund')
            ->assertStatus(422)->assertJsonPath('code', 'insufficient_funds');

        $this->assertSame(100.0, $this->balance($this->client));
        $this->assertSame('pending', Milestone::first()->status);
        $this->assertSame('awaiting_funding', Escrow::first()->status);
        $this->assertBooksBalance();
    }

    public function test_funding_everything_is_all_or_nothing(): void
    {
        $this->setUpEscrow();
        $this->fund($this->client, 900);

        $this->as($this->client)->postJson("/api/projects/{$this->project->id}/escrow/fund")->assertStatus(422)->assertJsonPath('code', 'insufficient_funds');
        $this->assertSame(900.0, $this->balance($this->client), 'the first milestone was not kept');
        $this->assertSame(0, Milestone::where('status', 'funded')->count());

        $this->fund($this->client, 100);
        $this->as($this->client)->postJson("/api/projects/{$this->project->id}/escrow/fund")
            ->assertOk()->assertJsonPath('data.totals.held', 1000)->assertJsonPath('data.status', 'active');
        $this->assertSame(0.0, $this->balance($this->client));
        $this->assertSame(1000.0, $this->balance(Account::ESCROW));
    }

    // ---- delivering and paying --------------------------------------------------------------------------------------

    public function test_the_freelancer_delivers_and_the_client_pays_minus_the_fee(): void
    {
        [$design, $build] = $this->setUpEscrow();
        $this->fund($this->client, 1000);
        $this->as($this->client)->postJson("/api/projects/{$this->project->id}/escrow/fund")->assertOk();

        $this->as($this->client)->postJson("/api/milestones/{$design->id}/submit", ['note' => 'All finished'])->assertForbidden();
        $this->as($this->stranger)->postJson("/api/milestones/{$design->id}/submit")->assertForbidden();
        $this->as($this->freelancer)->postJson("/api/milestones/{$design->id}/submit", ['note' => 'All screens are in Figma.'])
            ->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.submissionNote', 'All screens are in Figma.');

        $this->as($this->client)->getJson('/api/notifications')->assertJsonPath('data.0.type', 'milestone.submitted');

        $this->as($this->stranger)->postJson("/api/milestones/{$design->id}/approve")->assertForbidden();
        $this->as($this->freelancer)->postJson("/api/milestones/{$design->id}/approve")->assertForbidden();
        $this->as($this->client)->postJson("/api/milestones/{$design->id}/approve")
            ->assertOk()->assertJsonPath('data.status', 'released')->assertJsonPath('data.fee', 40);

        $this->assertSame(360.0, $this->balance($this->freelancer), '400 minus the 10% platform fee');
        $this->assertSame(40.0, $this->balance(Account::REVENUE));
        $this->assertSame(600.0, $this->balance(Account::ESCROW), 'the other milestone is still protected');
        $this->assertBooksBalance();

        $this->as($this->freelancer)->getJson('/api/notifications')
            ->assertJsonPath('data.0.message', "Payment released for \"Design\" on \"{$this->project->title}\": 360 USD added to your wallet.");
        $this->as($this->freelancer)->getJson('/api/wallet/transactions')
            ->assertJsonPath('data.0.label', "Payment received: Design ({$this->project->title})")->assertJsonPath('data.0.amount', 360);
        $this->as($this->client)->getJson("/api/projects/{$this->project->id}/escrow")->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.totals', ['total' => 1000, 'held' => 600, 'released' => 400, 'refunded' => 0]);

        // Not twice, and the project is not finished yet.
        $this->as($this->client)->postJson("/api/milestones/{$design->id}/approve")->assertStatus(422)->assertJsonPath('code', 'milestone_wrong_state');
        $this->assertSame(Project::STATUS_IN_PROGRESS, $this->project->fresh()->status);
        $this->as($this->client)->postJson("/api/projects/{$this->project->id}/complete")->assertStatus(422)->assertJsonPath('code', 'escrow_incomplete');

        $this->as($this->client)->postJson("/api/milestones/{$build->id}/approve")->assertOk();
        $this->assertSame(Escrow::COMPLETED, Escrow::first()->status);
        $this->assertSame(Project::STATUS_COMPLETED, $this->project->fresh()->status, 'the last payment completes the project');
        $this->assertSame(900.0, $this->balance($this->freelancer));
        $this->assertSame(100.0, $this->balance(Account::REVENUE));
        $this->assertSame(0.0, $this->balance(Account::ESCROW));
        $this->assertBooksBalance();

        $this->as($this->client)->getJson('/api/notifications')->assertJsonPath('data.0.type', 'escrow.completed');
    }

    public function test_work_must_be_funded_before_it_is_delivered_or_paid(): void
    {
        [$design] = $this->setUpEscrow();

        $this->as($this->freelancer)->postJson("/api/milestones/{$design->id}/submit")->assertStatus(422)->assertJsonPath('code', 'milestone_not_funded');
        $this->as($this->client)->postJson("/api/milestones/{$design->id}/approve")->assertStatus(422)->assertJsonPath('code', 'milestone_not_funded');
        $this->as($this->client)->postJson("/api/milestones/{$design->id}/request-changes", ['note' => 'Please fix this'])
            ->assertStatus(422)->assertJsonPath('code', 'milestone_not_submitted');
    }

    public function test_the_client_can_pay_without_waiting_for_a_delivery(): void
    {
        [$design] = $this->setUpEscrow();
        $this->fund($this->client, 1000);
        $this->as($this->client)->postJson("/api/milestones/{$design->id}/fund")->assertOk();

        $this->as($this->client)->postJson("/api/milestones/{$design->id}/approve")->assertOk()->assertJsonPath('data.status', 'released');
        $this->assertSame(360.0, $this->balance($this->freelancer));
    }

    public function test_changes_can_be_requested_on_delivered_work(): void
    {
        [$design] = $this->setUpEscrow();
        $this->fund($this->client, 1000);
        $this->as($this->client)->postJson("/api/milestones/{$design->id}/fund")->assertOk();
        $this->as($this->freelancer)->postJson("/api/milestones/{$design->id}/submit", ['note' => 'First draft'])->assertOk();

        $this->as($this->client)->postJson("/api/milestones/{$design->id}/request-changes", [])->assertJsonValidationErrors('note');
        $this->as($this->freelancer)->postJson("/api/milestones/{$design->id}/request-changes", ['note' => 'Make the logo bigger'])->assertForbidden();
        $this->as($this->client)->postJson("/api/milestones/{$design->id}/request-changes", ['note' => 'Make the logo bigger'])
            ->assertOk()->assertJsonPath('data.status', 'funded')->assertJsonPath('data.changesNote', 'Make the logo bigger');

        $this->as($this->freelancer)->getJson('/api/notifications')->assertJsonPath('data.0.type', 'milestone.changes_requested');
        $this->assertSame(400.0, $this->balance(Account::ESCROW), 'the money stays protected');

        $this->as($this->freelancer)->postJson("/api/milestones/{$design->id}/submit", ['note' => 'Second draft'])
            ->assertOk()->assertJsonPath('data.changesNote', null);
    }

    public function test_the_platform_fee_follows_the_admin_setting_and_never_loses_a_cent(): void
    {
        Setting::put('fees.site_percent', 7.5);
        $project = $this->hiredProject($this->client, User::factory()->create(), 0.99);
        $this->as($this->client)->postJson("/api/projects/{$project->id}/escrow", ['milestones' => [['title' => 'Tiny job', 'amount' => 0.99]]])->assertCreated();
        $this->fund($this->client, 1);
        $milestone = Milestone::latest('id')->first();

        $this->as($this->client)->postJson("/api/milestones/{$milestone->id}/fund")->assertOk();
        $this->as($this->client)->postJson("/api/milestones/{$milestone->id}/approve")->assertOk();

        $this->assertSame(0.07, $this->balance(Account::REVENUE), '7.5% of 0.99 = 0.07425, rounded to the cent');
        $this->assertSame(0.92, $this->balance(Escrow::latest('id')->first()->payees->first()->user));
        $this->assertBooksBalance();
    }

    public function test_a_payment_in_another_currency_uses_that_currencys_wallet(): void
    {
        $project = $this->hiredProject($this->client, User::factory()->create(), 500, 'SAR');
        $this->as($this->client)->postJson("/api/projects/{$project->id}/escrow", ['milestones' => [['title' => 'Everything', 'amount' => 500]]])->assertCreated();
        $this->fund($this->client, 500, 'USD');

        $milestone = Milestone::latest('id')->first();
        $this->as($this->client)->postJson("/api/milestones/{$milestone->id}/fund")->assertStatus(422)->assertJsonPath('code', 'insufficient_funds');

        $this->fund($this->client, 500, 'SAR');
        $this->as($this->client)->postJson("/api/milestones/{$milestone->id}/fund")->assertOk();
        $this->assertSame(500.0, $this->balance($this->client, 'USD'));
        $this->assertSame(0.0, $this->balance($this->client, 'SAR'));
        $this->assertSame(500.0, $this->balance(Account::ESCROW, 'SAR'));
        $this->assertBooksBalance();
    }

    public function test_my_escrows_lists_both_sides(): void
    {
        $this->setUpEscrow();

        $this->as($this->client)->getJson('/api/me/escrows')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.project.id', $this->project->id);
        $this->as($this->freelancer)->getJson('/api/me/escrows')->assertJsonPath('meta.total', 1);
        $this->as($this->stranger)->getJson('/api/me/escrows')->assertJsonPath('data', []);
    }

    public function test_payouts_to_a_team_split_by_share_without_losing_a_cent(): void
    {
        $escrow = Escrow::factory()->create();
        $users = User::factory()->count(3)->create();
        $payees = collect([[3333, true], [3333, false], [3334, false]])->map(
            fn ($row, $i) => EscrowPayee::create(['escrow_id' => $escrow->id, 'user_id' => $users[$i]->id, 'share_bp' => $row[0], 'is_lead' => $row[1]]),
        );

        $payouts = app(EscrowService::class)->distribute(1001, $payees);

        $this->assertSame(1001, array_sum($payouts));
        $this->assertSame([$users[0]->id => 335, $users[1]->id => 333, $users[2]->id => 333], $payouts, 'rounding leftovers go to the lead');
        $this->assertSame([$users[0]->id => 10000], app(EscrowService::class)->distribute(10000, $payees->take(1)->map(fn ($p) => tap($p, fn ($x) => $x->share_bp = 10000))));
    }

    // ---- helpers ----------------------------------------------------------------------------------------------------

    private function as(User $user): static
    {
        return $this->actingAs($user, 'sanctum');
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(): array
    {
        return ['milestones' => [['title' => 'Design', 'amount' => 400], ['title' => 'Build', 'amount' => 600]]];
    }

    /**
     * Escrow with two milestones, Design (400) and Build (600).
     *
     * @return array{0: Milestone, 1: Milestone}
     */
    private function setUpEscrow(): array
    {
        $this->as($this->client)->postJson("/api/projects/{$this->project->id}/escrow", $this->plan())->assertCreated();

        return [Milestone::where('title', 'Design')->first(), Milestone::where('title', 'Build')->first()];
    }
}
