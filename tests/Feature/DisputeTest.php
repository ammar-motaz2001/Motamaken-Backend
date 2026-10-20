<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Dispute;
use App\Models\Escrow;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\HandlesMoney;
use Tests\TestCase;

class DisputeTest extends TestCase
{
    use HandlesMoney, RefreshDatabase;

    private User $client;

    private User $freelancer;

    private User $stranger;

    private User $admin;

    private Project $project;

    private Milestone $milestone;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->client = User::factory()->create(['first_name' => 'Cleo', 'last_name' => 'Client']);
        $this->freelancer = User::factory()->create(['first_name' => 'Fay', 'last_name' => 'Freelancer']);
        $this->stranger = User::factory()->create();
        $this->admin = User::factory()->create(['is_admin' => true, 'first_name' => 'Ada', 'last_name' => 'Admin']);
        $this->project = $this->hiredProject($this->client, $this->freelancer, 1000);

        // One milestone of 1000, paid into escrow and delivered.
        $this->fund($this->client, 1000);
        $this->as($this->client)->postJson("/api/projects/{$this->project->id}/escrow", ['milestones' => [['title' => 'Everything', 'amount' => 1000]]])->assertCreated();
        $this->milestone = Milestone::firstOrFail();
        $this->as($this->client)->postJson("/api/milestones/{$this->milestone->id}/fund")->assertOk();
        $this->as($this->freelancer)->postJson("/api/milestones/{$this->milestone->id}/submit", ['note' => 'Here it is'])->assertOk();
    }

    // ---- opening --------------------------------------------------------------------------------------------------

    public function test_the_client_opens_a_dispute_and_the_milestone_freezes(): void
    {
        $case = $this->open($this->client)
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.caseNumber', fn ($n) => preg_match('/^DSP-\d{5}$/', $n) === 1)
            ->assertJsonPath('data.openedBy.fullName', 'Cleo Client')
            ->assertJsonPath('data.against.fullName', 'Fay Freelancer')
            ->assertJsonPath('data.milestone', ['id' => $this->milestone->id, 'title' => 'Everything', 'amount' => 1000, 'status' => 'disputed'])
            ->assertJsonPath('data.permissions', ['canPost' => true, 'canWithdraw' => true, 'canConcede' => false, 'canResolve' => false, 'canAppeal' => false])
            ->assertJsonPath('data.timeline.0.body', 'Dispute opened.')
            ->json('data');

        $this->assertSame('disputed', $this->milestone->fresh()->status);
        $this->assertSame(1000.0, $this->balance(Account::ESCROW), 'the money stays in escrow');

        $this->as($this->freelancer)->getJson('/api/notifications')->assertJsonPath('data.0.type', 'dispute.opened')
            ->assertJsonPath('data.0.message', "Cleo Client opened dispute {$case['caseNumber']} on \"Everything\" (\"{$this->project->title}\"). The milestone is frozen until it is settled.");
        $this->as($this->admin)->getJson('/api/notifications')->assertJsonPath('data.0.type', 'admin.dispute_opened');

        $this->as($this->freelancer)->getJson("/api/disputes/{$case['id']}")->assertOk()
            ->assertJsonPath('data.permissions', ['canPost' => true, 'canWithdraw' => false, 'canConcede' => true, 'canResolve' => false, 'canAppeal' => false]);
    }

    public function test_the_freelancer_can_open_one_too(): void
    {
        $this->open($this->freelancer)->assertCreated()->assertJsonPath('data.against.fullName', 'Cleo Client');
    }

    public function test_only_the_two_sides_can_open_and_only_once_on_a_live_milestone(): void
    {
        $this->open($this->stranger)->assertForbidden();
        $this->open($this->client, ['reason' => 'rude'])->assertJsonValidationErrors('reason');
        $this->open($this->client, ['description' => 'too short'])->assertJsonValidationErrors('description');

        $this->open($this->client)->assertCreated();
        $this->open($this->freelancer)->assertStatus(422)->assertJsonPath('code', 'milestone_not_disputable');

        $pending = Milestone::factory()->create(['escrow_id' => $this->milestone->escrow_id, 'position' => 2, 'amount_cents' => 100]);
        $this->open($this->client, [], $pending)->assertStatus(422)->assertJsonPath('code', 'milestone_not_disputable');
    }

    public function test_a_frozen_milestone_cannot_be_paid_delivered_or_changed(): void
    {
        $this->open($this->client)->assertCreated();

        $this->as($this->client)->postJson("/api/milestones/{$this->milestone->id}/approve")->assertStatus(422)->assertJsonPath('code', 'milestone_wrong_state');
        $this->as($this->client)->postJson("/api/milestones/{$this->milestone->id}/request-changes", ['note' => 'More changes please'])->assertStatus(422);
        $this->as($this->freelancer)->postJson("/api/milestones/{$this->milestone->id}/submit")->assertStatus(422);
        $this->assertSame(1000.0, $this->balance(Account::ESCROW));
        $this->assertSame(0.0, $this->balance($this->freelancer));
    }

    // ---- the case timeline ----------------------------------------------------------------------------------------

    public function test_both_sides_write_and_upload_evidence_and_admin_notes_stay_private(): void
    {
        $id = $this->open($this->client)->json('data.id');

        $this->as($this->freelancer)->postJson("/api/disputes/{$id}/messages", ['body' => 'I delivered everything on time.'])
            ->assertCreated()->assertJsonPath('data.author.fullName', 'Fay Freelancer')->assertJsonPath('data.kind', 'message');

        $evidence = $this->as($this->client)->post("/api/disputes/{$id}/messages", [
            'body' => 'Screenshot of the missing page', 'file' => UploadedFile::fake()->create('proof.png', 20, 'image/png'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.attachment.name', 'proof.png')->json('data');

        $this->get($evidence['attachment']['url'])->assertOk()->assertHeader('Content-Disposition', 'inline; filename=proof.png');
        $this->get($evidence['attachment']['url'].'x')->assertForbidden();

        $this->as($this->stranger)->postJson("/api/disputes/{$id}/messages", ['body' => 'Hello'])->assertForbidden();
        $this->as($this->stranger)->getJson("/api/disputes/{$id}")->assertForbidden();
        $this->as($this->client)->postJson("/api/disputes/{$id}/messages", [])->assertJsonValidationErrors('body');
        $this->as($this->client)->post("/api/disputes/{$id}/messages", ['file' => UploadedFile::fake()->create('virus.exe', 1)], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('file');

        $this->as($this->admin)->postJson("/api/admin/disputes/{$id}/messages", ['body' => 'Client looks right.', 'internal' => true])
            ->assertCreated()->assertJsonPath('data.kind', 'admin_note');
        $this->as($this->admin)->postJson("/api/admin/disputes/{$id}/messages", ['body' => 'Please send the contract.'])
            ->assertCreated()->assertJsonPath('data.kind', 'message');

        $bodies = fn (User $who) => array_column($this->as($who)->getJson("/api/disputes/{$id}")->json('data.timeline'), 'body');
        $this->assertNotContains('Client looks right.', $bodies($this->client));
        $this->assertNotContains('Client looks right.', $bodies($this->freelancer));
        $this->assertContains('Client looks right.', $bodies($this->admin));
        $this->assertContains('Please send the contract.', $bodies($this->freelancer));

        $this->as($this->client)->getJson('/api/notifications')->assertJsonPath('data.0.type', 'dispute.message');
        $this->app['auth']->forgetGuards();
        $this->getJson(route('disputes.attachment', 1))->assertForbidden();
    }

    public function test_my_disputes_lists_only_my_cases(): void
    {
        $id = $this->open($this->client)->json('data.id');

        $this->as($this->client)->getJson('/api/disputes')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
        $this->as($this->freelancer)->getJson('/api/disputes')->assertJsonPath('meta.total', 1);
        $this->as($this->stranger)->getJson('/api/disputes')->assertJsonPath('data', []);
        $this->as($this->admin)->getJson("/api/disputes/{$id}")->assertOk()->assertJsonPath('data.permissions.canResolve', true);
    }

    // ---- ending it ----------------------------------------------------------------------------------------------------

    public function test_withdrawing_puts_the_milestone_back(): void
    {
        $id = $this->open($this->client)->json('data.id');

        $this->as($this->freelancer)->postJson("/api/disputes/{$id}/withdraw")->assertForbidden();
        $this->as($this->client)->postJson("/api/disputes/{$id}/withdraw")->assertOk()->assertJsonPath('data.status', 'withdrawn');

        $this->assertSame('submitted', $this->milestone->fresh()->status, 'back to the delivered state it was in');
        $this->as($this->client)->postJson("/api/disputes/{$id}/withdraw")->assertStatus(422)->assertJsonPath('code', 'dispute_closed');
        $this->as($this->client)->postJson("/api/disputes/{$id}/messages", ['body' => 'Too late'])->assertStatus(422)->assertJsonPath('code', 'dispute_closed');
        $this->as($this->client)->postJson("/api/milestones/{$this->milestone->id}/approve")->assertOk();
    }

    public function test_the_freelancer_can_give_in_and_refund_the_client(): void
    {
        $id = $this->open($this->client)->json('data.id');

        $this->as($this->client)->postJson("/api/disputes/{$id}/concede")->assertForbidden();
        $this->as($this->stranger)->postJson("/api/disputes/{$id}/concede")->assertForbidden();
        $this->as($this->freelancer)->postJson("/api/disputes/{$id}/concede")
            ->assertOk()->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.resolution', 'client_refund');

        $this->assertSame(1000.0, $this->balance($this->client));
        $this->assertSame(0.0, $this->balance($this->freelancer));
        $this->assertSame(0.0, $this->balance(Account::REVENUE), 'no fee on money that goes back');
        $this->assertSame('refunded', $this->milestone->fresh()->status);
        $this->assertSame(Escrow::COMPLETED, Escrow::first()->status);
        $this->assertSame(Project::STATUS_CLOSED, $this->project->fresh()->status, 'nothing was paid out, so the project was cancelled');
        $this->assertBooksBalance();
    }

    public function test_the_client_can_give_in_and_pay_the_freelancer(): void
    {
        $id = $this->open($this->freelancer)->json('data.id');

        $this->as($this->client)->postJson("/api/disputes/{$id}/concede")->assertOk()->assertJsonPath('data.resolution', 'freelancer_payout');

        $this->assertSame(900.0, $this->balance($this->freelancer));
        $this->assertSame(100.0, $this->balance(Account::REVENUE));
        $this->assertSame(Project::STATUS_COMPLETED, $this->project->fresh()->status);
        $this->assertBooksBalance();
    }

    public function test_an_admin_takes_the_case_and_splits_the_money(): void
    {
        $id = $this->open($this->client)->json('data.id');

        $this->as($this->client)->postJson("/api/admin/disputes/{$id}/review")->assertForbidden();
        $this->as($this->admin)->getJson('/api/admin/disputes')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
        $this->as($this->admin)->postJson("/api/admin/disputes/{$id}/review")->assertOk()
            ->assertJsonPath('data.status', 'under_review')->assertJsonPath('data.assignedTo.fullName', 'Ada Admin');
        $this->as($this->client)->getJson('/api/notifications')->assertJsonPath('data.0.message', 'An admin took dispute '.sprintf('DSP-%05d', $id).' and is reviewing it.');

        $resolve = fn (array $body) => $this->as($this->admin)->postJson("/api/admin/disputes/{$id}/resolve", $body);
        $resolve([])->assertJsonValidationErrors(['resolution', 'note']);
        $resolve(['resolution' => 'split', 'note' => 'Half and half is fair.'])->assertJsonValidationErrors('clientPercent');
        $resolve(['resolution' => 'split', 'clientPercent' => 100, 'note' => 'Half and half is fair.'])->assertJsonValidationErrors('clientPercent');
        $resolve(['resolution' => 'keep', 'note' => 'Half and half is fair.'])->assertJsonValidationErrors('resolution');

        $resolve(['resolution' => 'split', 'clientPercent' => 30, 'note' => 'Part of the work was missing.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.resolution', 'split')
            ->assertJsonPath('data.clientPercent', 30)
            ->assertJsonPath('data.resolutionNote', 'Part of the work was missing.');

        // 1000: 300 back to the client; the freelancer's 700 loses 10% = 70.
        $this->assertSame(300.0, $this->balance($this->client));
        $this->assertSame(630.0, $this->balance($this->freelancer));
        $this->assertSame(70.0, $this->balance(Account::REVENUE));
        $this->assertSame(0.0, $this->balance(Account::ESCROW));
        $this->assertBooksBalance();

        $milestone = $this->milestone->fresh();
        $this->assertSame(['released', 30000, 7000], [$milestone->status, $milestone->refunded_cents, $milestone->fee_cents]);
        $this->as($this->client)->getJson("/api/projects/{$this->project->id}/escrow")
            ->assertJsonPath('data.totals', ['total' => 1000, 'held' => 0, 'released' => 700, 'refunded' => 300])
            ->assertJsonPath('data.milestones.0.refunded', 300);

        foreach ([$this->client, $this->freelancer] as $party) {
            $this->as($party)->getJson('/api/notifications')->assertJsonPath('data.0.type', 'dispute.resolved');
        }
        $this->as($this->client)->getJson('/api/wallet/transactions')->assertJsonPath('data.0.label', "Dispute settled: Everything ({$this->project->title})")
            ->assertJsonPath('data.0.amount', 300);

        $resolve(['resolution' => 'client_refund', 'note' => 'Changing my mind later.'])->assertStatus(422)->assertJsonPath('code', 'dispute_closed');
        $this->assertSame(Project::STATUS_COMPLETED, $this->project->fresh()->status);
    }

    public function test_an_admin_can_pay_the_freelancer_or_refund_the_client_in_full(): void
    {
        $id = $this->open($this->client)->json('data.id');
        $this->as($this->admin)->postJson("/api/admin/disputes/{$id}/resolve", ['resolution' => 'freelancer_payout', 'note' => 'Work was delivered as agreed.'])->assertOk();
        $this->assertSame(900.0, $this->balance($this->freelancer));
        $this->assertSame(0.0, $this->balance($this->client));
        $this->assertBooksBalance();
    }

    public function test_a_resolved_case_is_visible_to_the_admin_list_only_when_asked(): void
    {
        $id = $this->open($this->client)->json('data.id');
        $this->as($this->admin)->postJson("/api/admin/disputes/{$id}/resolve", ['resolution' => 'client_refund', 'note' => 'Nothing was delivered.'])->assertOk();

        $this->as($this->admin)->getJson('/api/admin/disputes')->assertJsonPath('data', []);
        $this->as($this->admin)->getJson('/api/admin/disputes?status=resolved')->assertJsonPath('meta.total', 1);
        $this->as($this->admin)->getJson('/api/admin/disputes?status=bogus')->assertStatus(422);
        $this->assertSame(Dispute::RESOLVED, Dispute::first()->status);
    }

    // ---- helpers ----------------------------------------------------------------------------------------------------

    private function as(User $user): static
    {
        return $this->actingAs($user, 'sanctum');
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function open(User $who, array $override = [], ?Milestone $on = null): TestResponse
    {
        return $this->as($who)->postJson('/api/milestones/'.($on ?? $this->milestone)->id.'/dispute', [
            'reason' => 'not_delivered', 'description' => 'Half of the pages were never delivered despite the agreement.', ...$override,
        ]);
    }
}
