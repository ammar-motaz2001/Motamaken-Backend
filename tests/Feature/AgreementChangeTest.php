<?php

namespace Tests\Feature;

use App\Models\AgreementChange;
use App\Models\Escrow;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HandlesMoney;
use Tests\TestCase;

class AgreementChangeTest extends TestCase
{
    use HandlesMoney, RefreshDatabase;

    private User $client;

    private User $freelancer;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create();
        $this->freelancer = User::factory()->create();
        $this->project = $this->hiredProject($this->client, $this->freelancer, 1000);
        // A fixed starting deadline: the factory's random one could equal a requested change and make it "no change".
        Proposal::whereKey($this->project->accepted_proposal_id)->update(['delivery_days' => 10]);
    }

    public function test_a_price_and_deadline_change_is_requested_by_one_side_and_accepted_by_the_other(): void
    {
        $id = $this->as($this->freelancer)->postJson("/api/projects/{$this->project->id}/agreement-changes", [
            'type' => 'change', 'amount' => 1200, 'deliveryDays' => 14, 'reason' => 'The scope grew: two more pages.',
        ])->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.mine', true)->json('data.id');

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->client->id]);
        // Only the other side answers.
        $this->as($this->freelancer)->postJson("/api/agreement-changes/{$id}/accept")->assertStatus(422);
        $this->as($this->client)->postJson("/api/agreement-changes/{$id}/accept")->assertOk()->assertJsonPath('data.status', 'accepted');

        $proposal = Proposal::find($this->project->accepted_proposal_id);
        $this->assertEquals(1200, $proposal->amount);
        $this->assertSame(14, $proposal->delivery_days);
    }

    public function test_an_unfunded_payment_plan_is_removed_when_the_price_changes(): void
    {
        $this->as($this->client)->postJson("/api/projects/{$this->project->id}/escrow", ['milestones' => [['title' => 'All', 'amount' => 1000]]])->assertCreated();
        $id = $this->as($this->client)->postJson("/api/projects/{$this->project->id}/agreement-changes", ['type' => 'change', 'amount' => 800, 'reason' => 'Dropping the blog section.'])->json('data.id');
        $this->as($this->freelancer)->postJson("/api/agreement-changes/{$id}/accept")->assertOk();

        $this->assertSame(0, Escrow::where('project_id', $this->project->id)->count());
    }

    public function test_the_price_is_locked_once_money_is_in_escrow_but_the_deadline_can_still_change(): void
    {
        $this->fund($this->client, 1000);
        $this->as($this->client)->postJson("/api/projects/{$this->project->id}/escrow", ['milestones' => [['title' => 'All', 'amount' => 1000]]])->assertCreated();
        $this->as($this->client)->postJson('/api/milestones/'.Milestone::first()->id.'/fund')->assertOk();

        $this->as($this->freelancer)->postJson("/api/projects/{$this->project->id}/agreement-changes", ['type' => 'change', 'amount' => 1500, 'reason' => 'Asking for more money now.'])
            ->assertStatus(422)->assertJsonPath('code', 'price_locked');
        $this->as($this->freelancer)->postJson("/api/projects/{$this->project->id}/agreement-changes", ['type' => 'change', 'deliveryDays' => 30, 'reason' => 'Need more time for testing.'])->assertOk();
    }

    public function test_cancelling_by_agreement_refunds_the_escrow_to_the_client_and_closes_the_project(): void
    {
        $this->fund($this->client, 1000);
        $this->as($this->client)->postJson("/api/projects/{$this->project->id}/escrow", ['milestones' => [['title' => 'Alpha', 'amount' => 600], ['title' => 'Beta', 'amount' => 400]]])->assertCreated();
        $this->as($this->client)->postJson('/api/milestones/'.Milestone::where('title', 'Alpha')->first()->id.'/fund')->assertOk();
        $this->assertEquals(400, $this->balance($this->client));

        $id = $this->as($this->client)->postJson("/api/projects/{$this->project->id}/agreement-changes", ['type' => 'cancel', 'reason' => 'Our plans changed, sorry.'])->json('data.id');
        // One open request at a time.
        $this->as($this->freelancer)->postJson("/api/projects/{$this->project->id}/agreement-changes", ['type' => 'cancel', 'reason' => 'I also want to stop here.'])->assertStatus(422);
        $this->as($this->freelancer)->postJson("/api/agreement-changes/{$id}/accept")->assertOk();

        $this->assertEquals(1000, $this->balance($this->client));
        $this->assertSame(Project::STATUS_CLOSED, $this->project->fresh()->status);
        $this->assertSame(Escrow::CANCELLED, Escrow::first()->status);
        $this->assertBooksBalance();
    }

    public function test_declining_and_withdrawing_leave_the_agreement_unchanged_and_outsiders_are_kept_out(): void
    {
        $stranger = User::factory()->create();
        $this->as($stranger)->postJson("/api/projects/{$this->project->id}/agreement-changes", ['type' => 'cancel', 'reason' => 'Not my project at all.'])->assertForbidden();
        $this->as($stranger)->getJson("/api/projects/{$this->project->id}/agreement-changes")->assertForbidden();

        $first = $this->as($this->client)->postJson("/api/projects/{$this->project->id}/agreement-changes", ['type' => 'cancel', 'reason' => 'Thinking of stopping.'])->json('data.id');
        $this->as($this->freelancer)->postJson("/api/agreement-changes/{$first}/decline", ['note' => 'Almost done, let us finish.'])->assertOk()->assertJsonPath('data.status', 'declined');

        $second = $this->as($this->client)->postJson("/api/projects/{$this->project->id}/agreement-changes", ['type' => 'change', 'deliveryDays' => 3, 'reason' => 'Can you deliver sooner?'])->json('data.id');
        $this->as($this->freelancer)->postJson("/api/agreement-changes/{$second}/withdraw")->assertForbidden();
        $this->as($this->client)->postJson("/api/agreement-changes/{$second}/withdraw")->assertOk()->assertJsonPath('data.status', 'withdrawn');

        $this->assertSame(Project::STATUS_IN_PROGRESS, $this->project->fresh()->status);
        $this->as($this->freelancer)->getJson("/api/projects/{$this->project->id}/agreement-changes")->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(0, AgreementChange::where('status', 'pending')->count());
    }

    private function as(User $user): static
    {
        return $this->actingAs($user, 'sanctum');
    }
}
