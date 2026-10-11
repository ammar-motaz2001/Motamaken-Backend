<?php

namespace Tests\Feature;

use App\Enums\AuthMethod;
use App\Models\Draw;
use App\Models\PointTransaction;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use App\Services\RewardsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\HandlesMoney;
use Tests\TestCase;

class EngagementTest extends TestCase
{
    use HandlesMoney, RefreshDatabase;

    public function test_points_are_awarded_once_and_explained(): void
    {
        $user = User::factory()->create(['points' => 0]);
        $rewards = app(RewardsService::class);

        $rewards->award($user, 'identity_verified');
        $rewards->award($user, 'identity_verified');
        $rewards->award($user, 'review_left', 'review:1');
        $rewards->award($user, 'review_left', 'review:2');

        $this->assertSame(120, $user->fresh()->points);
        $this->actingAs($user, 'sanctum')->getJson('/api/me/points')->assertOk()
            ->assertJsonPath('balance', 120)->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.label', 'Left a review')
            ->assertJsonPath('earn', fn ($rules) => collect($rules)->firstWhere('reason', 'identity_verified')['done'] === true);
    }

    public function test_kyc_approval_and_completed_projects_earn_points_and_medals(): void
    {
        $client = User::factory()->create(['points' => 0]);
        $freelancer = User::factory()->create(['points' => 0]);
        $freelancer->forceFill(['kyc_status' => 'approved'])->save();

        $project = Project::factory()->create(['user_id' => $client->id]);
        $proposal = Proposal::factory()->create(['project_id' => $project->id, 'user_id' => $freelancer->id, 'status' => 'accepted']);
        $project->update(['accepted_proposal_id' => $proposal->id]);
        $project->update(['status' => Project::STATUS_COMPLETED]);

        $this->assertSame(120, $freelancer->fresh()->points, '100 for identity + 20 for the project');
        $this->assertSame(20, $client->fresh()->points);
        $medals = collect($this->actingAs($freelancer, 'sanctum')->getJson('/api/me/medals')->json('data'))->keyBy('code');
        $this->assertTrue($medals['verified']['earned']);
        $this->assertTrue($medals['first_job']['earned']);
        $this->assertSame(1, $medals['rising_star']['current']);
        $this->assertFalse($medals['rising_star']['earned']);
        $this->getJson("/api/users/{$freelancer->id}")->assertJsonCount(2, 'medals');
        $this->assertContains('medal.first_job', $freelancer->notifications()->pluck('data')->pluck('type'));
    }

    public function test_the_referrer_is_rewarded_when_the_invitee_verifies_and_sees_their_stats(): void
    {
        $referrer = User::factory()->create(['points' => 0]);
        $invitee = User::factory()->create(['referred_by' => $referrer->id, 'email_verified_at' => null, 'phone_verified_at' => null]);

        $this->postJson('/api/affiliate/visits', ['code' => strtolower($referrer->referral_code)])->assertJsonPath('recorded', true);
        $this->postJson('/api/affiliate/visits', ['code' => $referrer->referral_code])->assertJsonPath('recorded', false);
        $this->postJson('/api/affiliate/visits', ['code' => 'NOPE1234'])->assertJsonPath('recorded', false);

        $invitee->markVerified(AuthMethod::Email);
        $this->assertSame(25, $referrer->fresh()->points);

        $this->actingAs($referrer, 'sanctum')->getJson('/api/me/affiliate')->assertOk()
            ->assertJsonPath('stats.visits', 1)->assertJsonPath('stats.signups', 1)->assertJsonPath('stats.conversionRate', 100)
            ->assertJsonPath('tier.code', 'bronze')->assertJsonPath('nextTier.code', 'silver')->assertJsonCount(6, 'monthly')
            ->assertJsonPath('link', fn ($link) => str_ends_with($link, "/signup?ref={$referrer->referral_code}"));
        $this->actingAs($referrer, 'sanctum')->getJson('/api/me/affiliate/referrals')->assertJsonPath('data.0.id', $invitee->id)->assertJsonPath('data.0.qualified', false);
    }

    public function test_a_draw_takes_tickets_picks_winners_and_pays_cash_prizes(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $alice = User::factory()->create(['points' => 100]);
        $bob = User::factory()->create(['points' => 15]);

        $id = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/draws', [
            'titleEn' => 'Spring draw', 'titleAr' => 'سحب الربيع', 'ticketCost' => 10, 'maxTicketsPerUser' => 5,
            'startsAt' => now()->subMinute()->toIso8601String(), 'drawAt' => now()->addDay()->toIso8601String(),
            'prizes' => [
                ['rank' => 1, 'nameEn' => '100 USD', 'nameAr' => '100 دولار', 'kind' => 'cash', 'value' => 100, 'currency' => 'USD'],
                ['rank' => 2, 'nameEn' => 'Headphones', 'nameAr' => 'سماعات', 'kind' => 'item', 'value' => 50, 'currency' => 'USD'],
            ],
        ])->assertCreated()->json('data.id');

        $this->actingAs($alice, 'sanctum')->postJson("/api/draws/{$id}/entries", ['tickets' => 6])->assertJsonPath('code', 'too_many_tickets');
        $this->actingAs($alice, 'sanctum')->postJson("/api/draws/{$id}/entries", ['tickets' => 3])->assertOk()->assertJsonPath('data.me.tickets', 3);
        $this->actingAs($bob, 'sanctum')->postJson("/api/draws/{$id}/entries", ['tickets' => 2])->assertJsonPath('code', 'insufficient_points');
        $this->actingAs($bob, 'sanctum')->postJson("/api/draws/{$id}/entries", ['tickets' => 1])->assertOk();
        $this->assertSame(70, $alice->fresh()->points);
        $this->assertSame(-30, (int) PointTransaction::where('user_id', $alice->id)->sum('amount'));

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/draws/current')->assertJsonPath('data.totals', ['tickets' => 4, 'players' => 2])->assertJsonPath('data.me', null);

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/draws/{$id}/run")->assertOk()->assertJsonCount(2, 'winners');
        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/draws/{$id}/run")->assertJsonPath('code', 'draw_not_open');

        $first = Draw::find($id)->winners()->where('rank', 1)->first();
        $second = Draw::find($id)->winners()->where('rank', 2)->first();
        $this->actingAs($first->user, 'sanctum')->postJson("/api/draw-winners/{$first->id}/claim", ['method' => 'wallet'])->assertOk();
        $this->assertSame(100.0, $this->balance($first->user));
        $this->assertSame(-100.0, $this->balance(\App\Models\Account::MARKETING));
        $this->actingAs($first->user, 'sanctum')->postJson("/api/draw-winners/{$first->id}/claim", ['method' => 'wallet'])->assertJsonPath('code', 'already_claimed');
        $this->actingAs($second->user, 'sanctum')->postJson("/api/draw-winners/{$second->id}/claim", ['method' => 'wallet'])->assertJsonPath('code', 'not_cash');
        $this->actingAs($second->user, 'sanctum')->postJson("/api/draw-winners/{$second->id}/claim", ['method' => 'delivery'])->assertJsonValidationErrors('details.address');
        $this->actingAs($second->user, 'sanctum')->postJson("/api/draw-winners/{$second->id}/claim", ['method' => 'delivery', 'details' => [
            'name' => 'Bob', 'phone' => '+966500000000', 'address' => 'King Fahd Rd 1', 'city' => 'Riyadh', 'country' => 'Saudi Arabia',
        ]])->assertOk()->assertJsonPath('data.claimMethod', 'delivery');
        $this->assertBooksBalance();

        $this->getJson('/api/draws/history')->assertJsonPath('data.0.winners.0.rank', 1)->assertJsonPath('data.0.tickets', 4);
    }

    public function test_due_draws_are_run_by_the_schedule(): void
    {
        $draw = Draw::create(['title_en' => 'Old', 'title_ar' => 'قديم', 'ticket_cost' => 1, 'starts_at' => now()->subDays(2), 'draw_at' => now()->subMinute(),
            'prizes' => [['rank' => 1, 'name_en' => 'Mug', 'name_ar' => 'كوب', 'kind' => 'item', 'value' => 5, 'currency' => 'USD']]]);
        DB::table('draw_entries')->insert(['draw_id' => $draw->id, 'user_id' => User::factory()->create()->id, 'tickets' => 2, 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('draws:run')->assertSuccessful();
        $this->assertSame(Draw::DRAWN, $draw->fresh()->status);
        $this->assertSame(1, $draw->winners()->count());
    }

    public function test_a_complete_profile_earns_points_once(): void
    {
        $user = User::factory()->create(['points' => 0, 'avatar_path' => 'avatars/x.jpg']);
        $profile = Profile::factory()->create(['user_id' => $user->id, 'bio' => 'Designer', 'skills' => ['A', 'B', 'C']]);
        $profile->portfolio()->create(['title' => 'Work', 'image_path' => 'portfolio/x.png']);

        app(RewardsService::class)->checkProfileCompleted($user);
        app(RewardsService::class)->checkProfileCompleted($user);

        $this->assertSame(50, $user->fresh()->points);
    }
}
