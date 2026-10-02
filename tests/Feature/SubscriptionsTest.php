<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Milestone;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HandlesMoney;
use Tests\TestCase;

class SubscriptionsTest extends TestCase
{
    use HandlesMoney, RefreshDatabase;

    private User $user;

    private Plan $pro;

    private Plan $elite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
        $this->user = User::factory()->create(['first_name' => 'Sub', 'last_name' => 'Scriber']);
        Profile::factory()->create(['user_id' => $this->user->id, 'is_featured' => false]);
        $this->pro = Plan::firstWhere('code', 'freelancer-pro');
        $this->elite = Plan::firstWhere('code', 'freelancer-elite');
    }

    // ---- the plans page ------------------------------------------------------------------------------------------------

    public function test_the_plans_page_lists_plans_per_account_type(): void
    {
        $plans = $this->getJson('/api/plans?audience=freelancer')->assertOk();

        $this->assertSame(['freelancer-free', 'freelancer-pro', 'freelancer-elite'], array_column($plans->json('data'), 'code'));
        $plans->assertJsonPath('data.0', fn ($free) => $free['isFree'] === true && $free['price'] === 0 && ! array_key_exists('isCurrent', $free));
        $plans->assertJsonPath('data.1.price', 16.99)->assertJsonPath('data.1.isPopular', true)->assertJsonPath('data.1.name', 'Pro');
        $plans->assertJsonPath('data.1.perks', ['50 offers every month', 'Platform fee of only 8%', '10% off promotions']);
        $plans->assertJsonPath('data.2.perks.0', 'Unlimited offers every month');

        $this->getJson('/api/plans?audience=freelancer', ['Accept-Language' => 'ar'])->assertJsonPath('data.1.name', 'احترافي')->assertJsonPath('data.1.perks.0', '50 عرضاً كل شهر');
        $this->getJson('/api/plans')->assertJsonCount(8, 'data');
        $this->getJson('/api/plans?audience=pirate')->assertStatus(422);
    }

    public function test_the_current_plan_is_marked(): void
    {
        $this->as($this->user)->getJson('/api/plans?audience=freelancer')->assertJsonPath('data.0.isCurrent', true)->assertJsonPath('data.1.isCurrent', false);

        $this->fund($this->user, 50);
        $this->as($this->user)->postJson('/api/subscriptions', ['planId' => $this->pro->id])->assertCreated();

        $this->as($this->user)->getJson('/api/plans?audience=freelancer')->assertJsonPath('data.0.isCurrent', false)->assertJsonPath('data.1.isCurrent', true);
    }

    // ---- subscribing ----------------------------------------------------------------------------------------------------

    public function test_subscribing_charges_the_wallet_and_starts_the_plan(): void
    {
        $this->as($this->user)->postJson('/api/subscriptions', ['planId' => $this->pro->id])->assertStatus(422)->assertJsonPath('code', 'insufficient_funds');
        $this->assertDatabaseCount('subscriptions', 0);

        $this->fund($this->user, 20);
        $this->as($this->user)->postJson('/api/subscriptions', ['planId' => $this->pro->id])
            ->assertCreated()
            ->assertJsonPath('subscription.plan.code', 'freelancer-pro')
            ->assertJsonPath('subscription.status', 'active')
            ->assertJsonPath('subscription.autoRenew', true)
            ->assertJsonPath('subscription.currentPeriodEnd', fn ($end) => now()->addMonth()->diffInHours($end) < 2);

        $this->assertSame(3.01, $this->balance($this->user));
        $this->assertSame(16.99, $this->balance(Account::REVENUE));
        $this->assertBooksBalance();
        $this->as($this->user)->getJson('/api/notifications')->assertJsonPath('data.0.message', 'Your Pro plan is active until '.now()->addMonth()->toDateString().'.');
        $this->as($this->user)->getJson('/api/wallet/transactions')->assertJsonPath('data.0.label', 'Subscription')->assertJsonPath('data.0.amount', -16.99);

        $this->as($this->user)->postJson('/api/subscriptions', ['planId' => $this->pro->id])->assertStatus(422)->assertJsonPath('code', 'already_subscribed');
        $this->as($this->user)->postJson('/api/subscriptions', ['planId' => 999])->assertJsonValidationErrors('planId');
        $this->as($this->user)->postJson('/api/subscriptions', [])->assertJsonValidationErrors('planId');
    }

    public function test_upgrading_replaces_the_plan_and_choosing_free_stops_renewal(): void
    {
        $this->fund($this->user, 100);
        $this->as($this->user)->postJson('/api/subscriptions', ['planId' => $this->pro->id])->assertCreated();
        $this->as($this->user)->postJson('/api/subscriptions', ['planId' => $this->elite->id])->assertCreated()->assertJsonPath('subscription.plan.code', 'freelancer-elite');

        $this->assertSame(['cancelled', 'active'], Subscription::orderBy('id')->pluck('status')->all());
        $this->as($this->user)->getJson('/api/me/subscription')->assertJsonPath('subscription.plan.code', 'freelancer-elite');

        $free = Plan::firstWhere('code', 'freelancer-free');
        $this->as($this->user)->postJson('/api/subscriptions', ['planId' => $free->id])->assertOk()->assertJsonPath('subscription', null);
        $this->as($this->user)->getJson('/api/me/subscription')->assertJsonPath('subscription.autoRenew', false)->assertJsonPath('subscription.status', 'active');
    }

    public function test_an_inactive_plan_cannot_be_bought(): void
    {
        $this->pro->update(['is_active' => false]);
        $this->fund($this->user, 50);

        $this->as($this->user)->postJson('/api/subscriptions', ['planId' => $this->pro->id])->assertStatus(422)->assertJsonPath('code', 'plan_unavailable');
        $this->getJson('/api/plans?audience=freelancer')->assertJsonCount(2, 'data');
    }

    public function test_cancelling_keeps_the_plan_until_the_period_ends(): void
    {
        $this->as($this->user)->postJson('/api/subscriptions/cancel')->assertStatus(422)->assertJsonPath('code', 'no_subscription');
        $this->fund($this->user, 50);
        $this->as($this->user)->postJson('/api/subscriptions', ['planId' => $this->pro->id])->assertCreated();

        $this->as($this->user)->postJson('/api/subscriptions/cancel')->assertOk()->assertJsonPath('data.autoRenew', false)->assertJsonPath('data.status', 'active');
        $this->as($this->user)->getJson('/api/me/subscription')->assertJsonPath('benefits.feePercent', 8);
        $this->as($this->user)->postJson('/api/subscriptions/resume')->assertOk()->assertJsonPath('data.autoRenew', true);
    }

    // ---- what a plan changes --------------------------------------------------------------------------------------------

    public function test_a_plan_lowers_the_platform_fee(): void
    {
        $this->fund($this->user, 50);
        $this->as($this->user)->postJson('/api/subscriptions', ['planId' => $this->pro->id])->assertCreated();
        $this->as($this->user)->getJson('/api/me/subscription')->assertJsonPath('benefits.feePercent', 8);

        $client = User::factory()->create();
        $project = $this->hiredProject($client, $this->user, 1000);
        $this->fund($client, 1000);
        $this->as($client)->postJson("/api/projects/{$project->id}/escrow", ['milestones' => [['title' => 'All of it', 'amount' => 1000]]])->assertCreated();
        $milestone = Milestone::firstOrFail();
        $this->as($client)->postJson("/api/milestones/{$milestone->id}/fund")->assertOk();
        $this->as($client)->postJson("/api/milestones/{$milestone->id}/approve")->assertOk()->assertJsonPath('data.fee', 80);

        $this->assertEqualsWithDelta(920 + 33.01, $this->balance($this->user), 0.001, '8% instead of 10%');
        $this->assertEqualsWithDelta(80 + 16.99, $this->balance(Account::REVENUE), 0.001);
        $this->assertBooksBalance();
    }

    public function test_the_site_fee_wins_when_it_is_lower_than_the_plan_fee(): void
    {
        $this->fund($this->user, 50);
        $this->as($this->user)->postJson('/api/subscriptions', ['planId' => $this->pro->id])->assertCreated();
        Setting::put('fees.site_percent', 5);

        $this->as($this->user)->getJson('/api/me/subscription')->assertJsonPath('benefits.feePercent', 5);
    }

    public function test_offers_are_limited_per_month_by_plan(): void
    {
        config(['marketplace.subscriptions.free_monthly_offers' => 2]);
        $send = fn () => $this->as($this->user)->postJson('/api/projects/'.Project::factory()->create()->id.'/proposals', [
            'amount' => 100, 'deliveryDays' => 3, 'message' => 'I can do this quickly and well.',
        ]);

        $send()->assertCreated();
        $send()->assertCreated();
        $send()->assertStatus(422)->assertJsonPath('code', 'offer_limit_reached')->assertJsonPath('limit', 2)->assertJsonPath('upgrade', true)
            ->assertJsonPath('message', 'You reached your limit of 2 offers this month. Upgrade your plan to send more.');
        $this->as($this->user)->getJson('/api/me/subscription')->assertJsonPath('benefits.offersLimit', 2)->assertJsonPath('benefits.offersSentThisMonth', 2);

        $this->fund($this->user, 50);
        $this->as($this->user)->postJson('/api/subscriptions', ['planId' => $this->pro->id])->assertCreated();
        $send()->assertCreated();
        $this->as($this->user)->getJson('/api/me/subscription')->assertJsonPath('benefits.offersLimit', 50);

        // The count restarts every month.
        $this->travel(1)->month();
        $this->assertSame(0, app(SubscriptionService::class)->offersSentThisMonth($this->user));
    }

    public function test_the_elite_plan_has_no_offer_limit(): void
    {
        config(['marketplace.subscriptions.free_monthly_offers' => 1]);
        $this->fund($this->user, 50);
        $this->as($this->user)->postJson('/api/subscriptions', ['planId' => $this->elite->id])->assertCreated();
        Proposal::factory()->count(5)->create(['user_id' => $this->user->id]);

        $this->as($this->user)->getJson('/api/me/subscription')->assertJsonPath('benefits.offersLimit', null);
        $this->as($this->user)->postJson('/api/projects/'.Project::factory()->create()->id.'/proposals', [
            'amount' => 100, 'deliveryDays' => 3, 'message' => 'I can do this quickly and well.',
        ])->assertCreated();
    }

    // ---- renewals -------------------------------------------------------------------------------------------------------

    public function test_a_subscription_renews_when_the_period_ends(): void
    {
        $this->fund($this->user, 50);
        $this->as($this->user)->postJson('/api/subscriptions', ['planId' => $this->pro->id])->assertCreated();

        $this->travel(15)->days();
        $this->artisan('subscriptions:renew')->expectsOutput('Renewed 0, failed 0, expired 0.')->assertSuccessful();

        $this->travel(17)->days();
        $this->artisan('subscriptions:renew')->expectsOutput('Renewed 1, failed 0, expired 0.')->assertSuccessful();

        $this->assertEqualsWithDelta(50 - 2 * 16.99, $this->balance($this->user), 0.001);
        $this->assertSame(1, Subscription::count(), 'the same subscription moves on');
        $this->assertTrue(Subscription::first()->current_period_end->isFuture());
        $this->as($this->user)->getJson('/api/notifications')->assertJsonPath('data.0.type', 'subscription.renewed');
        $this->assertBooksBalance();

        // Running the job again the same day does nothing.
        $this->artisan('subscriptions:renew')->expectsOutput('Renewed 0, failed 0, expired 0.')->assertSuccessful();
    }

    public function test_a_failed_renewal_has_a_grace_period_and_then_the_plan_ends(): void
    {
        $this->fund($this->user, 17);
        $this->as($this->user)->postJson('/api/subscriptions', ['planId' => $this->pro->id])->assertCreated();

        $this->travel(32)->days();
        $this->artisan('subscriptions:renew')->expectsOutput('Renewed 0, failed 1, expired 0.')->assertSuccessful();
        $this->assertSame('past_due', Subscription::first()->status);
        $this->as($this->user)->getJson('/api/notifications')->assertJsonPath('data.0.message', 'We could not renew your Pro plan. Add money to your wallet within 3 days to keep it.');
        $this->as($this->user)->getJson('/api/me/subscription')->assertJsonPath('subscription.status', 'past_due');

        // The benefits last through the grace period, and a second failed attempt does not spam.
        $this->travel(1)->days();
        $this->artisan('subscriptions:renew')->assertSuccessful();
        $this->assertCount(2, $this->user->notifications, 'only one failure notice was sent');

        $this->fund($this->user, 20);
        $this->artisan('subscriptions:renew')->expectsOutput('Renewed 1, failed 0, expired 0.')->assertSuccessful();
        $this->assertSame('active', Subscription::first()->fresh()->status);
    }

    public function test_a_subscription_that_cannot_be_renewed_expires_after_the_grace_period(): void
    {
        $this->fund($this->user, 17);
        $this->as($this->user)->postJson('/api/subscriptions', ['planId' => $this->pro->id])->assertCreated();

        $this->travel(32)->days();
        $this->artisan('subscriptions:renew')->assertSuccessful();
        $this->travel(4)->days();
        $this->artisan('subscriptions:renew')->expectsOutput('Renewed 0, failed 0, expired 1.')->assertSuccessful();

        $this->assertSame('expired', Subscription::first()->status);
        $this->as($this->user)->getJson('/api/me/subscription')->assertJsonPath('subscription', null)->assertJsonPath('benefits.feePercent', 10);
    }

    public function test_a_cancelled_subscription_ends_instead_of_renewing(): void
    {
        $this->fund($this->user, 50);
        $this->as($this->user)->postJson('/api/subscriptions', ['planId' => $this->pro->id])->assertCreated();
        $this->as($this->user)->postJson('/api/subscriptions/cancel')->assertOk();

        $this->travel(32)->days();
        $this->artisan('subscriptions:renew')->expectsOutput('Renewed 0, failed 0, expired 1.')->assertSuccessful();

        $this->assertSame(33.01, $this->balance($this->user), 'no second charge');
        $this->as($this->user)->getJson('/api/notifications')->assertJsonPath('data.0.message', 'Your Pro plan has ended.');
    }

    // ---- promotions --------------------------------------------------------------------------------------------------------

    public function test_promotion_prices_follow_the_plan_discount(): void
    {
        $price = fn (string $kind, string $currency) => collect($this->as($this->user)->getJson('/api/promotions/prices')->json('data'))
            ->first(fn ($row) => $row['kind'] === $kind && $row['currency'] === $currency)['perDay'];

        $this->assertSame(2, $price('profile', 'USD'));
        $this->assertSame(11.25, $price('project', 'SAR'));

        $this->fund($this->user, 100);
        $this->as($this->user)->postJson('/api/subscriptions', ['planId' => $this->elite->id])->assertCreated();
        $this->assertSame(1.5, $price('profile', 'USD'), '25% off');
        $this->as($this->user)->getJson('/api/promotions/prices')->assertJsonPath('maxDays', 30);
    }

    public function test_buying_a_profile_promotion_features_the_profile_on_the_homepage(): void
    {
        $this->as($this->user)->postJson('/api/promotions', ['kind' => 'profile', 'days' => 7, 'currency' => 'USD'])->assertStatus(422)->assertJsonPath('code', 'insufficient_funds');
        $this->fund($this->user, 30);

        $promotion = $this->as($this->user)->postJson('/api/promotions', ['kind' => 'profile', 'days' => 7, 'currency' => 'USD'])
            ->assertCreated()->assertJsonPath('data.price', 14)->assertJsonPath('data.isActive', true)->assertJsonPath('data.project', null)->json('data');

        $this->assertSame(16.0, $this->balance($this->user));
        $this->assertSame(14.0, $this->balance(Account::REVENUE));
        $this->assertBooksBalance();

        $profile = $this->user->profile()->first();
        $this->assertTrue($profile->is_featured);
        $this->assertEqualsWithDelta(7, now()->diffInDays($profile->featured_until, false), 1);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/home')->assertJsonPath('featuredProfiles.0.userId', $this->user->id);

        // Buying again adds days to the end instead of restarting.
        $this->as($this->user)->postJson('/api/promotions', ['kind' => 'profile', 'days' => 3, 'currency' => 'USD'])->assertCreated();
        $this->assertEqualsWithDelta(10, now()->diffInDays($profile->fresh()->featured_until, false), 1);

        $this->as($this->user)->getJson('/api/me/promotions')->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.days', 3);
        $this->assertNotNull($promotion);
        $this->as($this->user)->getJson('/api/wallet/transactions')->assertJsonPath('data.0.label', 'Promotion');
    }

    public function test_buying_a_project_promotion(): void
    {
        $this->fund($this->user, 100);
        $mine = Project::factory()->create(['user_id' => $this->user->id]);
        $others = Project::factory()->create();
        $closed = Project::factory()->create(['user_id' => $this->user->id, 'status' => Project::STATUS_CLOSED]);
        $buy = fn (array $body) => $this->as($this->user)->postJson('/api/promotions', ['kind' => 'project', 'days' => 5, 'currency' => 'SAR', ...$body]);

        $buy([])->assertJsonValidationErrors('projectId');
        $buy(['projectId' => $others->id])->assertStatus(422)->assertJsonPath('code', 'project_not_yours');
        $buy(['projectId' => $closed->id])->assertStatus(422)->assertJsonPath('code', 'project_not_open');

        $this->fund($this->user, 100, 'SAR');
        $buy(['projectId' => $mine->id])->assertCreated()->assertJsonPath('data.price', 56.25)->assertJsonPath('data.project.id', $mine->id);

        $this->assertTrue($mine->fresh()->is_featured);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/home')->assertJsonPath('distinctiveProjects.0.id', $mine->id);
    }

    public function test_promotion_validation(): void
    {
        $post = fn (array $override) => $this->as($this->user)->postJson('/api/promotions', ['kind' => 'profile', 'days' => 3, 'currency' => 'USD', ...$override]);
        $this->fund($this->user, 100);

        $post(['kind' => 'banner'])->assertJsonValidationErrors('kind');
        $post(['days' => 0])->assertJsonValidationErrors('days');
        $post(['days' => 31])->assertJsonValidationErrors('days');
        $post(['currency' => 'XXX'])->assertJsonValidationErrors('currency');

        $noProfile = User::factory()->create();
        $this->fund($noProfile, 100);
        $this->as($noProfile)->postJson('/api/promotions', ['kind' => 'profile', 'days' => 3, 'currency' => 'USD'])->assertStatus(422)->assertJsonPath('code', 'profile_required');
    }

    // ---- admin ---------------------------------------------------------------------------------------------------------------

    public function test_admins_manage_plans(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->fund($this->user, 50);
        $this->as($this->user)->postJson('/api/subscriptions', ['planId' => $this->pro->id])->assertCreated();

        $this->as($this->user)->getJson('/api/admin/plans')->assertForbidden();
        $list = $this->as($admin)->getJson('/api/admin/plans')->assertJsonCount(8, 'data')->json('data');
        $this->assertSame(1, collect($list)->firstWhere('code', 'freelancer-pro')['subscribers']);
        $this->assertSame(0, collect($list)->firstWhere('code', 'freelancer-elite')['subscribers']);

        $this->as($admin)->patchJson("/api/admin/plans/{$this->pro->id}", ['price' => 19.99, 'features' => ['fee_percent' => 7], 'isPopular' => false])
            ->assertOk()->assertJsonPath('data.price', 19.99)->assertJsonPath('data.features.fee_percent', 7)->assertJsonPath('data.features.monthly_offers', 50);

        $this->as($admin)->patchJson("/api/admin/plans/{$this->pro->id}", ['price' => -1])->assertStatus(422);
        $this->as($admin)->patchJson("/api/admin/plans/{$this->pro->id}", ['features' => ['fee_percent' => 80]])->assertStatus(422);
        $this->as($admin)->patchJson("/api/admin/plans/{$this->pro->id}", ['isActive' => false])->assertOk();
        $this->getJson('/api/plans?audience=freelancer')->assertJsonCount(2, 'data');
    }

    private function as(User $user): static
    {
        return $this->actingAs($user->fresh(), 'sanctum');
    }
}
