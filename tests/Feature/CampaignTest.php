<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Campaign;
use App\Models\CampaignVisit;
use App\Models\Profile;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\HandlesMoney;
use Tests\TestCase;

class CampaignTest extends TestCase
{
    use HandlesMoney, RefreshDatabase;

    private const BROWSER = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148';

    private User $owner;

    private User $promoter;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['first_name' => 'Ola', 'last_name' => 'Owner']);
        $this->promoter = User::factory()->create(['first_name' => 'Pia', 'last_name' => 'Promoter']);
        $this->project = Project::factory()->create(['user_id' => $this->owner->id]);
    }

    // ---- creating and launching -----------------------------------------------------------------------------------------

    public function test_a_campaign_starts_as_a_draft_and_costs_nothing_until_launched(): void
    {
        $this->create()
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.target', ['type' => 'project', 'id' => $this->project->id, 'path' => "/projects/{$this->project->id}"])
            ->assertJsonPath('data.budget', 10)
            ->assertJsonPath('data.costPerVisit', 0.5)
            ->assertJsonPath('data.rewardPerVisit', 0.4)
            ->assertJsonPath('data.feePercent', 20)
            ->assertJsonPath('data.filters', ['block_bots' => true, 'unique_window_hours' => 24, 'max_visits_per_promoter_per_hour' => 100, 'allowed_countries' => []])
            ->assertJsonPath('data.stats.visits', 0);

        $this->assertSame(0.0, $this->balance($this->owner));
    }

    public function test_campaign_validation(): void
    {
        $other = Project::factory()->create();

        $this->as($this->owner)->postJson('/api/campaigns', [])->assertJsonValidationErrors(['title', 'targetType', 'currency', 'budget', 'costPerVisit']);
        $this->create(['targetType' => 'website'])->assertJsonValidationErrors('targetType');
        $this->create(['targetId' => null])->assertJsonValidationErrors('targetId');
        $this->create(['targetId' => $other->id])->assertStatus(422)->assertJsonPath('code', 'project_not_yours');
        $this->create(['targetType' => 'profile', 'targetId' => null])->assertStatus(422)->assertJsonPath('code', 'profile_required');
        $this->create(['costPerVisit' => 0.01])->assertStatus(422)->assertJsonPath('code', 'cost_too_small')->assertJsonPath('message', 'The cost per visit must be at least 0.05 USD.');
        $this->create(['budget' => 5])->assertStatus(422)->assertJsonPath('code', 'budget_too_small');
        $this->create(['currency' => 'XXX'])->assertJsonValidationErrors('currency');
        $this->create(['filters' => ['unique_window_hours' => 0]])->assertJsonValidationErrors('filters.unique_window_hours');
        $this->create(['filters' => ['allowed_countries' => ['EGY']]])->assertJsonValidationErrors('filters.allowed_countries.0');
        $this->assertDatabaseCount('campaigns', 0);

        Profile::factory()->create(['user_id' => $this->owner->id]);
        $this->create(['targetType' => 'profile', 'targetId' => null])->assertCreated()->assertJsonPath('data.target.path', "/users/{$this->owner->id}");
    }

    public function test_launching_moves_the_budget_out_of_the_wallet(): void
    {
        $id = $this->create()->json('data.id');

        $this->as($this->owner)->postJson("/api/campaigns/{$id}/launch")->assertStatus(422)->assertJsonPath('code', 'insufficient_funds');

        $this->fund($this->owner, 15);
        $this->as($this->promoter)->postJson("/api/campaigns/{$id}/launch")->assertForbidden();
        $this->as($this->owner)->postJson("/api/campaigns/{$id}/launch")->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.isRunning', true);

        $this->assertSame(5.0, $this->balance($this->owner));
        $this->assertSame(10.0, $this->balance(Account::CAMPAIGNS));
        $this->assertBooksBalance();
        $this->as($this->owner)->postJson("/api/campaigns/{$id}/launch")->assertStatus(422)->assertJsonPath('code', 'campaign_not_draft');
        $this->as($this->owner)->getJson('/api/wallet/transactions')->assertJsonPath('data.0.label', 'Campaign budget')->assertJsonPath('data.0.amount', -10);
    }

    public function test_a_running_campaign_only_changes_its_filters_and_end_date(): void
    {
        $id = $this->launched();

        $this->as($this->owner)->patchJson("/api/campaigns/{$id}", ['filters' => ['allowed_countries' => ['eg', 'SA'], 'block_bots' => false]])
            ->assertOk()->assertJsonPath('data.filters.allowed_countries', ['EG', 'SA'])->assertJsonPath('data.filters.block_bots', false)
            ->assertJsonPath('data.filters.unique_window_hours', 24);
        $this->as($this->owner)->patchJson("/api/campaigns/{$id}", ['endsAt' => now()->addWeek()->toIso8601String()])->assertOk();
        $this->as($this->owner)->patchJson("/api/campaigns/{$id}", ['budget' => 500])->assertStatus(422)->assertJsonPath('code', 'campaign_locked');
        $this->as($this->owner)->patchJson("/api/campaigns/{$id}", ['targetType' => 'profile'])->assertJsonValidationErrors('targetType');
        $this->as($this->promoter)->patchJson("/api/campaigns/{$id}", ['title' => 'Mine now'])->assertForbidden();
    }

    // ---- promoting and visits ---------------------------------------------------------------------------------------------

    public function test_a_promoter_joins_and_a_valid_visit_pays_them(): void
    {
        $id = $this->launched();

        $this->as($this->owner)->postJson("/api/campaigns/{$id}/join")->assertStatus(422)->assertJsonPath('code', 'own_campaign');
        $this->as($this->promoter)->getJson('/api/campaigns/available')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.rewardPerVisit', 0.4);

        $link = $this->as($this->promoter)->postJson("/api/campaigns/{$id}/join")->assertCreated()
            ->assertJsonPath('data.campaign.id', $id)->assertJsonPath('data.link', fn ($l) => str_contains($l, '/r/'))->json('data');
        $this->as($this->promoter)->postJson("/api/campaigns/{$id}/join")->assertOk()->assertJsonPath('data.code', $link['code']);

        $this->visit($link['code'])->assertOk()->assertExactJson(['recorded' => true, 'valid' => true, 'path' => "/projects/{$this->project->id}"]);

        $this->assertSame(0.4, $this->balance($this->promoter));
        $this->assertSame(0.1, $this->balance(Account::REVENUE), 'the platform keeps 20%');
        $this->assertSame(9.5, $this->balance(Account::CAMPAIGNS));
        $this->assertBooksBalance();

        $this->visit('NOPE')->assertExactJson(['recorded' => false, 'valid' => false, 'path' => null]);
        $this->postJson('/api/campaign-visits', [])->assertJsonValidationErrors('code');
    }

    public function test_bad_traffic_is_recorded_but_not_paid(): void
    {
        $id = $this->launched(['filters' => ['allowed_countries' => ['EG'], 'max_visits_per_promoter_per_hour' => 2]]);
        $code = $this->join($id);

        $this->visit($code, ['User-Agent' => 'Googlebot/2.1'], '10.0.0.1')->assertJsonPath('valid', false);
        $this->visit($code, ['User-Agent' => ''], '10.0.0.2')->assertJsonPath('valid', false);
        $this->visit($code, ['CF-IPCountry' => 'US'], '10.0.0.3')->assertJsonPath('valid', false);
        $this->visit($code, ['CF-IPCountry' => 'EG'], '10.0.0.4')->assertJsonPath('valid', true);
        $this->visit($code, ['CF-IPCountry' => 'EG'], '10.0.0.4')->assertJsonPath('valid', false);
        $this->visit($code, [], '10.0.0.5')->assertJsonPath('valid', true, 'an unknown country is let through');
        $this->visit($code, [], '10.0.0.6')->assertJsonPath('valid', false);

        $reasons = CampaignVisit::orderBy('id')->pluck('reject_reason')->all();
        $this->assertSame(['bot', 'bot', 'country', null, 'duplicate', null, 'rate_limited'], $reasons);
        $this->assertSame(0.8, $this->balance($this->promoter), 'two paid visits');
        $this->assertBooksBalance();

        // The rate limit is per hour, and the duplicate window is 24 hours.
        $this->travel(2)->hours();
        $this->visit($code, [], '10.0.0.7')->assertJsonPath('valid', true);
        $this->visit($code, ['CF-IPCountry' => 'EG'], '10.0.0.4')->assertJsonPath('valid', false);
        $this->travel(1)->day();
        $this->visit($code, ['CF-IPCountry' => 'EG'], '10.0.0.4')->assertJsonPath('valid', true);
    }

    public function test_the_promoter_and_the_owner_cannot_earn_from_their_own_visits(): void
    {
        $code = $this->join($this->launched());

        $this->actingAs($this->promoter, 'sanctum')->withHeaders(['User-Agent' => self::BROWSER])
            ->postJson('/api/campaign-visits', ['code' => $code])->assertJsonPath('valid', false);
        $this->actingAs($this->owner, 'sanctum')->withHeaders(['User-Agent' => self::BROWSER])
            ->postJson('/api/campaign-visits', ['code' => $code])->assertJsonPath('valid', false);

        $this->assertSame(['own_link', 'own_link'], CampaignVisit::pluck('reject_reason')->all());
        $this->assertSame(0.0, $this->balance($this->promoter));
    }

    public function test_a_paused_campaign_records_visits_without_paying(): void
    {
        $id = $this->launched();
        $code = $this->join($id);

        $this->as($this->owner)->postJson("/api/campaigns/{$id}/pause")->assertOk()->assertJsonPath('data.status', 'paused')->assertJsonPath('data.isRunning', false);
        $this->visit($code)->assertJsonPath('valid', false)->assertJsonPath('path', "/projects/{$this->project->id}");
        $this->assertSame('not_running', CampaignVisit::first()->reject_reason);
        $this->as($this->promoter)->postJson("/api/campaigns/{$id}/join")->assertOk(); // already joined: still gets the link back
        $this->as(User::factory()->create())->postJson("/api/campaigns/{$id}/join")->assertStatus(422)->assertJsonPath('code', 'campaign_not_active');
        $this->as($this->promoter)->getJson('/api/campaigns/available')->assertJsonPath('data', []);

        $this->as($this->owner)->postJson("/api/campaigns/{$id}/resume")->assertOk()->assertJsonPath('data.status', 'active');
        $this->visit($code, [], '10.9.9.9')->assertJsonPath('valid', true);
    }

    public function test_the_campaign_completes_and_refunds_when_the_budget_runs_out(): void
    {
        // 10.20 budget at 0.50 a visit: after 20 visits 0.20 is left, too little for another.
        $id = $this->launched(['budget' => 10.2]);
        $code = $this->join($id);

        foreach (range(1, 20) as $i) {
            $this->visit($code, [], "10.1.0.{$i}")->assertJsonPath('valid', true);
        }

        $campaign = Campaign::find($id);
        $this->assertSame('completed', $campaign->status);
        $this->assertSame(1000, $campaign->spent_cents);
        $this->assertSame(8.0, $this->balance($this->promoter));
        $this->assertSame(2.0, $this->balance(Account::REVENUE));
        $this->assertSame(0.0, $this->balance(Account::CAMPAIGNS), 'nothing left behind');
        $this->assertEqualsWithDelta(5.0, $this->balance($this->owner), 0.001, '15 - 10.20 + 0.20 back');
        $this->assertBooksBalance();
        $this->as($this->owner)->getJson('/api/notifications')->assertJsonPath('data.0.message', 'Your campaign "Spring launch" has finished. 0.2 USD was returned to your wallet.');

        $this->visit($code, [], '10.1.1.1')->assertJsonPath('valid', false);
        $this->assertSame('not_running', CampaignVisit::latest('id')->first()->reject_reason);
    }

    public function test_stopping_a_campaign_refunds_what_is_left(): void
    {
        $id = $this->launched();
        $this->visit($this->join($id))->assertJsonPath('valid', true);

        $this->as($this->promoter)->postJson("/api/campaigns/{$id}/cancel")->assertForbidden();
        $this->as($this->owner)->postJson("/api/campaigns/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.remaining', 9.5);

        $this->assertSame(14.5, $this->balance($this->owner));
        $this->assertSame(0.0, $this->balance(Account::CAMPAIGNS));
        $this->assertBooksBalance();
        $this->as($this->owner)->postJson("/api/campaigns/{$id}/cancel")->assertStatus(422)->assertJsonPath('code', 'campaign_closed');
        $this->as($this->owner)->postJson("/api/campaigns/{$id}/resume")->assertStatus(422);

        // A draft is simply dropped, with nothing to refund and no notification.
        $draft = $this->create()->json('data.id');
        $this->as($this->owner)->postJson("/api/campaigns/{$draft}/cancel")->assertOk();
        $this->assertSame(14.5, $this->balance($this->owner));
    }

    public function test_campaigns_close_when_their_end_date_passes(): void
    {
        $id = $this->launched(['endsAt' => now()->addDays(2)->toIso8601String()]);
        $this->visit($this->join($id))->assertJsonPath('valid', true);

        $this->artisan('campaigns:close-ended')->expectsOutput('Closed 0 campaign(s).')->assertSuccessful();
        $this->travel(3)->days();
        $this->visit(Campaign::find($id)->promoters()->first()->code, [], '10.7.7.7')->assertJsonPath('valid', false);
        $this->artisan('campaigns:close-ended')->expectsOutput('Closed 1 campaign(s).')->assertSuccessful();

        $this->assertSame('completed', Campaign::find($id)->status);
        $this->assertSame(14.5, $this->balance($this->owner));
        $this->assertBooksBalance();
    }

    // ---- reading -----------------------------------------------------------------------------------------------------------

    public function test_the_owner_sees_stats_promoters_visits_and_the_daily_chart(): void
    {
        $id = $this->launched();
        $code = $this->join($id);
        $this->visit($code, [], '10.2.0.1');
        $this->visit($code, [], '10.2.0.1');
        $this->visit($code, ['User-Agent' => 'curl/8.0'], '10.2.0.2');
        $this->visit($code, [], '10.2.0.3');

        $this->as($this->owner)->getJson("/api/campaigns/{$id}")->assertOk()
            ->assertJsonPath('data.stats.visits', 4)
            ->assertJsonPath('data.stats.validVisits', 2)
            ->assertJsonPath('data.stats.validRate', 50)
            ->assertJsonPath('data.stats.promoters', 1)
            ->assertJsonPath('data.stats.rejections', [
                ['reason' => 'bot', 'label' => 'Robot or script', 'count' => 1],
                ['reason' => 'duplicate', 'label' => 'Repeat visitor', 'count' => 1],
            ])
            ->assertJsonPath('data.stats.topPromoters.0.user.fullName', 'Pia Promoter')
            ->assertJsonPath('data.spent', 1);

        $this->as($this->owner)->getJson("/api/campaigns/{$id}/visits")->assertJsonPath('meta.total', 4)->assertJsonPath('data.0.promoter.fullName', 'Pia Promoter');
        $this->as($this->owner)->getJson("/api/campaigns/{$id}/visits?reason=bot")->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.rejectLabel', 'Robot or script');
        $this->as($this->owner)->getJson("/api/campaigns/{$id}/visits?status=valid")->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.reward', 0.4);
        $this->as($this->owner)->getJson("/api/campaigns/{$id}/visits", ['Accept-Language' => 'ar'])->assertJsonPath('data.1.rejectLabel', 'روبوت أو برنامج آلي');
        $this->as($this->owner)->getJson("/api/campaigns/{$id}/promoters")->assertJsonPath('data.0.validVisits', 2)->assertJsonPath('data.0.rejectedVisits', 2)->assertJsonPath('data.0.earned', 0.8);

        $daily = $this->as($this->owner)->getJson("/api/campaigns/{$id}/daily?days=7")->assertOk()->json('data');
        $this->assertCount(7, $daily);
        $this->assertSame(['date' => now()->toDateString(), 'valid' => 2, 'rejected' => 2, 'spent' => 1], end($daily));

        $this->as($this->owner)->getJson('/api/me/campaigns')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.stats.validVisits', 2);
        $this->as($this->owner)->getJson('/api/me/campaigns?status=draft')->assertJsonPath('data', []);

        foreach (["/api/campaigns/{$id}", "/api/campaigns/{$id}/visits", "/api/campaigns/{$id}/promoters", "/api/campaigns/{$id}/daily"] as $url) {
            $this->as($this->promoter)->getJson($url)->assertForbidden();
        }
    }

    public function test_the_promoter_dashboard_adds_up_every_link(): void
    {
        $first = $this->launched();
        $other = User::factory()->create();
        $otherProject = Project::factory()->create(['user_id' => $other->id]);
        $this->fund($other, 50, 'SAR');
        $second = $this->as($other)->postJson('/api/campaigns', [
            'title' => 'Second', 'targetType' => 'project', 'targetId' => $otherProject->id, 'currency' => 'SAR', 'budget' => 40, 'costPerVisit' => 1,
        ])->json('data.id');
        $this->as($other)->postJson("/api/campaigns/{$second}/launch")->assertOk();

        $this->visit($this->join($first), [], '10.3.0.1');
        $this->visit($this->join($second), [], '10.3.0.1');
        $this->visit($this->join($second), ['User-Agent' => 'Googlebot'], '10.3.0.2');

        $dashboard = $this->as($this->promoter)->getJson('/api/me/promoter')->assertOk()
            ->assertJsonPath('totals.validVisits', 2)
            ->assertJsonPath('totals.rejectedVisits', 1)
            ->assertJsonCount(2, 'links')
            ->assertJsonCount(14, 'daily')
            ->assertJsonPath('daily.13.visits', 2);

        $this->assertEqualsCanonicalizing([['currency' => 'USD', 'amount' => 0.4], ['currency' => 'SAR', 'amount' => 0.8]], $dashboard->json('totals.earned'));
        $this->assertSame(0.8, $this->balance($this->promoter, 'SAR'));
        $this->assertBooksBalance();
    }

    public function test_the_website_proxy_passes_on_the_real_visitor_only_with_the_secret(): void
    {
        config(['services.frontend_proxy.secret' => 'shared-secret']);
        $code = $this->join($this->launched());
        $viaWebsite = fn (string $ip, string $secret = 'shared-secret') => $this->withServerVariables(['REMOTE_ADDR' => '172.16.0.10'])
            ->withHeaders(['User-Agent' => 'node', 'X-Proxy-Secret' => $secret, 'X-Client-IP' => $ip, 'X-Client-User-Agent' => self::BROWSER, 'X-Client-Country' => 'EG'])
            ->postJson('/api/campaign-visits', ['code' => $code]);

        // Two visitors through the same website server are two visitors.
        $viaWebsite('203.0.113.7')->assertJsonPath('valid', true);
        $viaWebsite('203.0.113.8')->assertJsonPath('valid', true);
        $this->assertSame(['EG', 'EG'], CampaignVisit::pluck('country')->all());

        // A forged secret changes nothing: the request counts as the server itself, with its own user agent.
        $viaWebsite('203.0.113.9', 'guess')->assertJsonPath('valid', false);
        $this->assertSame('bot', CampaignVisit::latest('id')->first()->reject_reason, 'the "node" agent of the server, not the forged browser');
        $this->assertNull(CampaignVisit::latest('id')->first()->country);
    }

    // ---- admin -------------------------------------------------------------------------------------------------------------

    public function test_admins_list_and_stop_campaigns(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $id = $this->launched();
        $this->visit($this->join($id));

        $this->as($this->owner)->getJson('/api/admin/campaigns')->assertForbidden();
        $this->as($admin)->getJson('/api/admin/campaigns')->assertOk()->assertJsonPath('data.0.owner.fullName', 'Ola Owner')->assertJsonPath('data.0.stats.validVisits', 1);
        $this->as($admin)->getJson('/api/admin/campaigns?status=cancelled')->assertJsonPath('data', []);

        $this->as($admin)->postJson("/api/admin/campaigns/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(14.5, $this->balance($this->owner));
        $this->assertBooksBalance();
    }

    public function test_admins_see_the_affiliate_programme(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $referrer = User::factory()->create(['first_name' => 'Rita', 'last_name' => 'Referrer']);
        User::factory()->count(2)->create(['referred_by' => $referrer->id]);

        $this->as($this->owner)->getJson('/api/admin/affiliate')->assertForbidden();
        $this->as($admin)->getJson('/api/admin/affiliate')->assertOk()
            ->assertJsonPath('totals.referrers', 1)
            ->assertJsonPath('totals.referredUsers', 2)
            ->assertJsonPath('totals.qualifiedReferrals', 0)
            ->assertJsonPath('totals.commissions', [])
            ->assertJsonPath('topReferrers', [])
            ->assertJsonPath('recent', []);
    }

    // ---- helpers -----------------------------------------------------------------------------------------------------------

    private function as(User $user): static
    {
        return $this->actingAs($user->fresh(), 'sanctum');
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function create(array $override = []): TestResponse
    {
        return $this->as($this->owner)->postJson('/api/campaigns', [
            'title' => 'Spring launch', 'targetType' => 'project', 'targetId' => $this->project->id,
            'currency' => 'USD', 'budget' => 10, 'costPerVisit' => 0.5, ...$override,
        ]);
    }

    /**
     * A running campaign: the owner has 15 USD and the 10 USD budget is in.
     *
     * @param  array<string, mixed>  $override
     */
    private function launched(array $override = []): int
    {
        $this->fund($this->owner, 15);
        $id = $this->create($override)->assertCreated()->json('data.id');
        $this->as($this->owner)->postJson("/api/campaigns/{$id}/launch")->assertOk();
        $this->app['auth']->forgetGuards();

        return $id;
    }

    private function join(int $campaign): string
    {
        $code = $this->as($this->promoter)->postJson("/api/campaigns/{$campaign}/join")->json('data.code');
        $this->app['auth']->forgetGuards();

        return $code;
    }

    /**
     * A visitor's browser reporting a visit.
     *
     * @param  array<string, string>  $headers
     */
    private function visit(string $code, array $headers = [], string $ip = '10.0.0.99'): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeaders(['User-Agent' => self::BROWSER, ...$headers])
            ->postJson('/api/campaign-visits', ['code' => $code]);
    }
}
