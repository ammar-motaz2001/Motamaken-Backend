<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Conversation;
use App\Models\Milestone;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HandlesMoney;
use Tests\TestCase;

class TeamTest extends TestCase
{
    use HandlesMoney, RefreshDatabase;

    private User $lead;

    private User $dana;

    private User $eli;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lead = $this->freelancer('Lena', 'Lead');
        $this->dana = $this->freelancer('Dana', 'Designer');
        $this->eli = $this->freelancer('Eli', 'Engineer');
        $this->client = User::factory()->create(['first_name' => 'Cleo', 'last_name' => 'Client']);
    }

    // ---- putting a team together ----------------------------------------------------------------------------------

    public function test_a_freelancer_creates_a_team_and_starts_with_everything(): void
    {
        $this->as($this->lead)->postJson('/api/teams', ['name' => 'Pixel Crew', 'description' => 'Websites and brands'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.members.0.user.fullName', 'Lena Lead')
            ->assertJsonPath('data.members.0.isLead', true)
            ->assertJsonPath('data.members.0.sharePercent', 100)
            ->assertJsonPath('data.readiness', ['ready' => false, 'blockers' => ['too_few_members']]);

        $this->as(User::factory()->create())->postJson('/api/teams', ['name' => 'No Profile Team'])->assertStatus(422);
        $this->as($this->lead)->postJson('/api/teams', ['name' => 'ab'])->assertJsonValidationErrors('name');
    }

    public function test_inviting_members_sets_shares_and_the_lead_keeps_the_rest(): void
    {
        $team = $this->team();

        $this->as($this->lead)->postJson("/api/teams/{$team->id}/members", ['userId' => $this->dana->id, 'roleTitle' => 'Designer', 'sharePercent' => 30])
            ->assertCreated()
            ->assertJsonPath('data.members.0.sharePercent', 70)
            ->assertJsonPath('data.members.1.user.fullName', 'Dana Designer')
            ->assertJsonPath('data.members.1.status', 'invited')
            ->assertJsonPath('data.members.1.expiresAt', fn ($at) => $at !== null)
            ->assertJsonPath('data.readiness.blockers', ['too_few_members', 'awaiting_acceptances']);

        $this->as($this->lead)->postJson("/api/teams/{$team->id}/members", ['userId' => $this->eli->id, 'sharePercent' => 25.5])->assertCreated()
            ->assertJsonPath('data.members.0.sharePercent', 44.5);

        $this->as($this->dana)->getJson('/api/notifications')->assertJsonPath('data.0.message', 'Lena Lead invited you to join the team "Pixel Crew" with a 30% share. Answer within 7 days.');
    }

    public function test_invitation_rules(): void
    {
        $team = $this->team();
        $invite = fn (array $body, ?User $by = null) => $this->as($by ?? $this->lead)->postJson("/api/teams/{$team->id}/members", $body);
        $stranger = User::factory()->create();

        $invite(['userId' => $this->eli->id, 'sharePercent' => 30], $this->dana)->assertForbidden();
        $invite([])->assertJsonValidationErrors(['userId', 'sharePercent']);
        $invite(['userId' => $this->lead->id, 'sharePercent' => 10])->assertJsonValidationErrors('userId');
        $invite(['userId' => $stranger->id, 'sharePercent' => 10])->assertStatus(422)->assertJsonPath('code', 'member_needs_profile');
        $invite(['userId' => $this->dana->id, 'sharePercent' => 0.5])->assertJsonValidationErrors('sharePercent');
        $invite(['userId' => $this->dana->id, 'sharePercent' => 99])->assertJsonValidationErrors('sharePercent');

        $invite(['userId' => $this->dana->id, 'sharePercent' => 60])->assertCreated();
        $invite(['userId' => $this->dana->id, 'sharePercent' => 10])->assertStatus(422)->assertJsonPath('code', 'already_member');
        $invite(['userId' => $this->eli->id, 'sharePercent' => 40])->assertStatus(422)->assertJsonPath('code', 'share_out_of_range')
            ->assertJsonPath('message', 'A share must be between 1% and 39%. The lead keeps at least 1%.');
        $invite(['userId' => $this->eli->id, 'sharePercent' => 39])->assertCreated()->assertJsonPath('data.members.0.sharePercent', 1);
    }

    public function test_members_can_accept_or_decline_and_the_lead_hears_about_it(): void
    {
        $team = $this->team();
        $danaId = $this->invite($team, $this->dana, 30);
        $eliId = $this->invite($team, $this->eli, 20);

        $this->as($this->dana)->getJson('/api/me/team-invitations')->assertJsonPath('data.0.team.name', 'Pixel Crew')
            ->assertJsonPath('data.0.sharePercent', 30)->assertJsonPath('data.0.invitedBy.fullName', 'Lena Lead');
        $this->as($this->eli)->postJson("/api/team-invitations/{$danaId}/accept")->assertNotFound();

        $this->as($this->dana)->postJson("/api/team-invitations/{$danaId}/accept")->assertOk()
            ->assertJsonPath('data.members.1.status', 'accepted')->assertJsonPath('data.myMembership.status', 'accepted');
        $this->as($this->dana)->postJson("/api/team-invitations/{$danaId}/accept")->assertStatus(422)->assertJsonPath('code', 'invitation_answered');
        $this->as($this->eli)->postJson("/api/team-invitations/{$eliId}/decline")->assertOk();

        $this->as($this->lead)->getJson('/api/notifications?perPage=2')
            ->assertJsonPath('data.0.message', 'Eli Engineer declined your invitation to "Pixel Crew".')
            ->assertJsonPath('data.1.message', 'Dana Designer accepted your invitation to "Pixel Crew".');
        $this->as($this->dana)->getJson('/api/me/team-invitations')->assertJsonPath('data', []);
    }

    public function test_changing_a_share_needs_the_approval_again(): void
    {
        $team = $this->team();
        $danaId = $this->invite($team, $this->dana, 30);
        $this->as($this->dana)->postJson("/api/team-invitations/{$danaId}/accept")->assertOk();

        $this->as($this->lead)->patchJson("/api/teams/{$team->id}/members/{$danaId}", ['roleTitle' => 'Art director'])
            ->assertOk()->assertJsonPath('data.members.1.status', 'accepted')->assertJsonPath('data.members.1.roleTitle', 'Art director');

        $this->as($this->lead)->patchJson("/api/teams/{$team->id}/members/{$danaId}", ['sharePercent' => 40])
            ->assertOk()->assertJsonPath('data.members.1.status', 'invited')->assertJsonPath('data.members.1.sharePercent', 40)->assertJsonPath('data.members.0.sharePercent', 60);
        $this->as($this->dana)->getJson('/api/notifications')->assertJsonPath('data.0.type', 'team.invited');

        $leadMember = $team->members()->where('is_lead', true)->first();
        $this->as($this->lead)->patchJson("/api/teams/{$team->id}/members/{$leadMember->id}", ['roleTitle' => 'Boss'])
            ->assertStatus(422)->assertJsonPath('code', 'lead_share_is_automatic');
    }

    public function test_removing_a_member_gives_the_share_back_to_the_lead(): void
    {
        $team = $this->team();
        $danaId = $this->invite($team, $this->dana, 30);
        $leadMember = $team->members()->where('is_lead', true)->first();

        $this->as($this->dana)->deleteJson("/api/teams/{$team->id}/members/{$danaId}")->assertForbidden();
        $this->as($this->lead)->deleteJson("/api/teams/{$team->id}/members/{$leadMember->id}")->assertStatus(422)->assertJsonPath('code', 'cannot_remove_lead');
        $this->as($this->lead)->deleteJson("/api/teams/{$team->id}/members/{$danaId}")->assertOk()->assertJsonCount(1, 'data.members')
            ->assertJsonPath('data.members.0.sharePercent', 100);
    }

    // ---- the publish gate -------------------------------------------------------------------------------------------

    public function test_a_team_is_published_only_when_everyone_accepted(): void
    {
        $team = $this->team();
        $this->as($this->lead)->postJson("/api/teams/{$team->id}/publish")->assertStatus(422)
            ->assertJsonPath('code', 'team_not_ready')->assertJsonPath('blockers', ['too_few_members']);

        $danaId = $this->invite($team, $this->dana, 30);
        $eliId = $this->invite($team, $this->eli, 20);
        $this->as($this->dana)->postJson("/api/team-invitations/{$danaId}/accept")->assertOk();
        $this->as($this->lead)->postJson("/api/teams/{$team->id}/publish")->assertJsonPath('blockers', ['awaiting_acceptances']);

        $this->as($this->eli)->postJson("/api/team-invitations/{$eliId}/decline")->assertOk();
        $this->as($this->lead)->postJson("/api/teams/{$team->id}/publish")->assertJsonPath('blockers', ['invitations_not_accepted']);
        $this->as($this->lead)->getJson("/api/teams/{$team->id}")->assertJsonPath('data.readiness.blockers', ['invitations_not_accepted']);

        $this->as($this->lead)->deleteJson("/api/teams/{$team->id}/members/{$eliId}")->assertOk();
        $this->as($this->dana)->postJson("/api/teams/{$team->id}/publish")->assertForbidden();
        $this->as($this->lead)->postJson("/api/teams/{$team->id}/publish")->assertOk()->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.readiness', ['ready' => false, 'blockers' => []]);
        $this->as($this->dana)->getJson('/api/notifications')->assertJsonPath('data.0.message', 'The team "Pixel Crew" is now published and can send offers.');

        // A published team is locked until it is taken back to a draft.
        $this->as($this->lead)->postJson("/api/teams/{$team->id}/members", ['userId' => $this->eli->id, 'sharePercent' => 10])->assertStatus(422)->assertJsonPath('code', 'team_not_draft');
        $this->as($this->lead)->postJson("/api/teams/{$team->id}/unpublish")->assertOk()->assertJsonPath('data.status', 'draft');
        $this->as($this->lead)->postJson("/api/teams/{$team->id}/members", ['userId' => $this->eli->id, 'sharePercent' => 10])->assertCreated();
    }

    public function test_invitations_expire_after_seven_days(): void
    {
        $team = $this->team();
        $danaId = $this->invite($team, $this->dana, 30);

        $this->travel(6)->days();
        $this->artisan('teams:expire-invitations')->expectsOutput('Expired 0 invitation(s).')->assertSuccessful();

        $this->travel(2)->days();
        $this->as($this->dana)->postJson("/api/team-invitations/{$danaId}/accept")->assertStatus(422)->assertJsonPath('code', 'invitation_expired');
        $this->as($this->dana)->getJson('/api/me/team-invitations')->assertJsonPath('data', []);

        $this->artisan('teams:expire-invitations')->expectsOutput('Expired 1 invitation(s).')->assertSuccessful();
        $this->assertSame('expired', TeamMember::find($danaId)->status);
        $this->as($this->lead)->getJson('/api/notifications')->assertJsonPath('data.0.message', 'The invitation for Dana Designer to join "Pixel Crew" expired.');
        $this->as($this->dana)->getJson('/api/notifications')->assertJsonPath('data.0.type', 'team.expired_you');

        $this->as($this->lead)->postJson("/api/teams/{$team->id}/publish")->assertJsonPath('blockers', ['too_few_members', 'invitations_not_accepted']);
        $this->as($this->lead)->postJson("/api/teams/{$team->id}/members/{$danaId}/resend")->assertOk()->assertJsonPath('data.members.1.status', 'invited');
        $this->as($this->dana)->postJson("/api/team-invitations/{$danaId}/accept")->assertOk();
    }

    public function test_members_can_leave_and_the_team_goes_back_to_a_draft(): void
    {
        $team = $this->publishedTeam();

        $this->as($this->lead)->postJson("/api/teams/{$team->id}/leave")->assertStatus(422)->assertJsonPath('code', 'cannot_remove_lead');
        $this->as($this->dana)->postJson("/api/teams/{$team->id}/leave")->assertOk();

        $team->refresh();
        $this->assertSame('draft', $team->status);
        $this->assertSame(10000, $team->members()->where('is_lead', true)->value('share_bp'));
        $this->as($this->lead)->getJson('/api/notifications')->assertJsonPath('data.0.message', 'Dana Designer left the team "Pixel Crew".');
        $this->as($this->dana)->getJson("/api/teams/{$team->id}")->assertForbidden();
    }

    public function test_only_the_involved_see_a_team(): void
    {
        $team = $this->publishedTeam();
        $outsider = User::factory()->create();

        $this->as($this->dana)->getJson("/api/teams/{$team->id}")->assertOk();
        $this->as($outsider)->getJson("/api/teams/{$team->id}")->assertForbidden();
        $this->as($this->lead)->getJson('/api/teams')->assertJsonPath('meta.total', 1);
        $this->as($this->dana)->getJson('/api/teams')->assertJsonPath('meta.total', 1);
        $this->as($outsider)->getJson('/api/teams')->assertJsonPath('data', []);
        $this->as($this->dana)->patchJson("/api/teams/{$team->id}", ['name' => 'Mine now'])->assertForbidden();
        $this->as($this->lead)->patchJson("/api/teams/{$team->id}", ['name' => 'Pixel Crew Pro'])->assertOk()->assertJsonPath('data.name', 'Pixel Crew Pro');
    }

    // ---- working on a project together ----------------------------------------------------------------------------------

    public function test_a_team_offer_pays_every_member_their_share_through_escrow(): void
    {
        $team = $this->publishedTeam();
        $project = Project::factory()->create(['user_id' => $this->client->id]);

        $offer = $this->as($this->lead)->postJson("/api/projects/{$project->id}/proposals", [
            'amount' => 1000, 'deliveryDays' => 10, 'message' => 'Our whole team will deliver this together.', 'teamId' => $team->id,
        ])->assertCreated()->assertJsonPath('data.team.name', 'Pixel Crew')->assertJsonCount(2, 'data.team.members')->json('data');

        $this->as($this->client)->postJson("/api/proposals/{$offer['id']}/accept")->assertOk();

        $group = Conversation::where('type', 'group')->where('project_id', $project->id)->first();
        $this->assertNotNull($group, 'the client and the team get a group chat');
        $this->assertSame('Team: Pixel Crew', $group->title);
        $this->assertEqualsCanonicalizing([$this->client->id, $this->lead->id, $this->dana->id], $group->participants()->pluck('users.id')->all());

        $this->fund($this->client, 1000);
        $this->as($this->client)->postJson("/api/projects/{$project->id}/escrow", ['milestones' => [['title' => 'All of it', 'amount' => 1000]]])
            ->assertCreated()->assertJsonCount(2, 'data.payees')
            ->assertJsonPath('data.payees.0', fn ($payee) => $payee['isLead'] === true && $payee['sharePercent'] === 70);

        $milestone = Milestone::firstOrFail();
        $this->as($this->client)->postJson("/api/milestones/{$milestone->id}/fund")->assertOk();
        $this->as($this->dana)->postJson("/api/milestones/{$milestone->id}/submit")->assertForbidden();
        $this->as($this->lead)->postJson("/api/milestones/{$milestone->id}/submit", ['note' => 'Done by the whole team'])->assertOk();
        $this->as($this->dana)->getJson('/api/wallet')->assertJsonPath('data.0.incoming', 300);

        $this->as($this->client)->postJson("/api/milestones/{$milestone->id}/approve")->assertOk();

        // 1000 - 10% fee = 900, split 70/30.
        $this->assertSame(630.0, $this->balance($this->lead));
        $this->assertSame(270.0, $this->balance($this->dana));
        $this->assertSame(100.0, $this->balance(Account::REVENUE));
        $this->assertBooksBalance();
        $this->assertSame(Project::STATUS_COMPLETED, $project->fresh()->status);
        $this->as($this->dana)->getJson('/api/notifications')->assertJsonPath('data.1.message', "Payment released for \"All of it\" on \"{$project->title}\": 270 USD added to your wallet.");
    }

    public function test_team_offers_have_rules(): void
    {
        $team = $this->publishedTeam();
        $draft = $this->team('Drafty');
        $project = Project::factory()->create(['user_id' => $this->client->id]);
        $offer = fn (User $who, int $teamId, ?Project $on = null) => $this->as($who)->postJson('/api/projects/'.($on ?? $project)->id.'/proposals', [
            'amount' => 100, 'deliveryDays' => 3, 'message' => 'We can do this together.', 'teamId' => $teamId,
        ]);

        $offer($this->dana, $team->id)->assertStatus(422)->assertJsonPath('code', 'team_not_yours');
        $offer($this->lead, $draft->id)->assertStatus(422)->assertJsonPath('code', 'team_not_active');
        $offer($this->lead, 999)->assertJsonValidationErrors('teamId');

        $request = Project::factory()->influencerRequest()->create(['user_id' => $this->client->id]);
        Profile::where('user_id', $this->lead->id)->update(['type' => 'influencer']);
        $offer($this->lead, $team->id, $request)->assertStatus(422)->assertJsonPath('code', 'team_projects_only');
    }

    public function test_a_team_must_still_be_published_when_its_offer_is_accepted(): void
    {
        $team = $this->publishedTeam();
        $project = Project::factory()->create(['user_id' => $this->client->id]);
        $offerId = $this->as($this->lead)->postJson("/api/projects/{$project->id}/proposals", [
            'amount' => 500, 'deliveryDays' => 5, 'message' => 'We can do this together.', 'teamId' => $team->id,
        ])->json('data.id');

        $this->as($this->lead)->postJson("/api/teams/{$team->id}/unpublish")->assertOk();
        $this->as($this->client)->postJson("/api/proposals/{$offerId}/accept")->assertStatus(422)->assertJsonPath('code', 'team_not_active');
        $this->assertSame('pending', Proposal::find($offerId)->status);
    }

    public function test_a_team_working_on_a_project_is_locked(): void
    {
        $team = $this->publishedTeam();
        $project = Project::factory()->create(['user_id' => $this->client->id]);
        $offerId = $this->as($this->lead)->postJson("/api/projects/{$project->id}/proposals", [
            'amount' => 500, 'deliveryDays' => 5, 'message' => 'We can do this together.', 'teamId' => $team->id,
        ])->json('data.id');
        $this->as($this->client)->postJson("/api/proposals/{$offerId}/accept")->assertOk();

        $this->as($this->lead)->postJson("/api/teams/{$team->id}/unpublish")->assertStatus(422)->assertJsonPath('code', 'team_in_use');
        $this->as($this->lead)->postJson("/api/teams/{$team->id}/disband")->assertStatus(422)->assertJsonPath('code', 'team_in_use');
        $this->as($this->dana)->postJson("/api/teams/{$team->id}/leave")->assertStatus(422)->assertJsonPath('code', 'team_in_use');

        $this->as($this->client)->postJson("/api/projects/{$project->id}/complete")->assertOk();
        $this->as($this->lead)->postJson("/api/teams/{$team->id}/disband")->assertOk()->assertJsonPath('data.status', 'disbanded');
        $this->as($this->lead)->getJson('/api/teams')->assertJsonPath('data', []);
    }

    public function test_only_the_team_lead_speaks_for_the_team_in_a_dispute(): void
    {
        $team = $this->publishedTeam();
        $project = Project::factory()->create(['user_id' => $this->client->id]);
        $offerId = $this->as($this->lead)->postJson("/api/projects/{$project->id}/proposals", [
            'amount' => 1000, 'deliveryDays' => 5, 'message' => 'We can do this together.', 'teamId' => $team->id,
        ])->json('data.id');
        $this->as($this->client)->postJson("/api/proposals/{$offerId}/accept")->assertOk();
        $this->fund($this->client, 1000);
        $this->as($this->client)->postJson("/api/projects/{$project->id}/escrow", ['milestones' => [['title' => 'All of it', 'amount' => 1000]]])->assertCreated();
        $milestone = Milestone::firstOrFail();
        $this->as($this->client)->postJson("/api/milestones/{$milestone->id}/fund")->assertOk();

        $body = ['reason' => 'payment', 'description' => 'The client keeps changing the scope of the work.'];
        $this->as($this->dana)->postJson("/api/milestones/{$milestone->id}/dispute", $body)->assertForbidden();
        $id = $this->as($this->lead)->postJson("/api/milestones/{$milestone->id}/dispute", $body)->assertCreated()->assertJsonPath('data.against.fullName', 'Cleo Client')->json('data.id');

        // The other team member can write on the timeline, but cannot withdraw or give in.
        $this->as($this->dana)->postJson("/api/disputes/{$id}/messages", ['body' => 'I confirm what Lena said.'])->assertCreated();
        $this->as($this->dana)->getJson("/api/disputes/{$id}")->assertJsonPath('data.permissions', ['canPost' => true, 'canWithdraw' => false, 'canConcede' => false, 'canResolve' => false]);
        $this->as($this->dana)->postJson("/api/disputes/{$id}/withdraw")->assertForbidden();

        // A split pays the team by share.
        $admin = User::factory()->create(['is_admin' => true]);
        $this->as($admin)->postJson("/api/admin/disputes/{$id}/resolve", ['resolution' => 'split', 'clientPercent' => 20, 'note' => 'Scope changed during the work.'])->assertOk();
        // 800 to the team, minus 10% = 720, split 70/30.
        $this->assertSame(200.0, $this->balance($this->client));
        $this->assertSame(504.0, $this->balance($this->lead));
        $this->assertSame(216.0, $this->balance($this->dana));
        $this->assertBooksBalance();
    }

    // ---- helpers ----------------------------------------------------------------------------------------------------

    private function as(User $user): static
    {
        return $this->actingAs($user->fresh(), 'sanctum');
    }

    private function freelancer(string $first, string $last): User
    {
        $user = User::factory()->create(['first_name' => $first, 'last_name' => $last]);
        Profile::factory()->create(['user_id' => $user->id, 'job_title' => $last]);

        return $user;
    }

    private function team(string $name = 'Pixel Crew'): Team
    {
        $id = $this->as($this->lead)->postJson('/api/teams', ['name' => $name])->assertCreated()->json('data.id');

        return Team::findOrFail($id);
    }

    private function invite(Team $team, User $user, float $share): int
    {
        $this->as($this->lead)->postJson("/api/teams/{$team->id}/members", ['userId' => $user->id, 'sharePercent' => $share])->assertCreated();

        return TeamMember::where('team_id', $team->id)->where('user_id', $user->id)->value('id');
    }

    /**
     * Lena (lead, 70%) and Dana (30%), both in, published.
     */
    private function publishedTeam(): Team
    {
        $team = $this->team();
        $danaId = $this->invite($team, $this->dana, 30);
        $this->as($this->dana)->postJson("/api/team-invitations/{$danaId}/accept")->assertOk();
        $this->as($this->lead)->postJson("/api/teams/{$team->id}/publish")->assertOk();

        return $team->refresh();
    }
}
