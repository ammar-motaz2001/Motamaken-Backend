<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HandlesMoney;
use Tests\TestCase;

class AdminFinanceTest extends TestCase
{
    use HandlesMoney, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    public function test_only_admins_reach_the_admin_panel(): void
    {
        $user = User::factory()->create();

        foreach (['/api/admin/withdrawals', '/api/admin/ledger/transactions', '/api/admin/ledger/trial-balance', '/api/admin/settings'] as $url) {
            $this->actingAs($user, 'sanctum')->getJson($url)->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/settings')->assertUnauthorized();
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/settings')->assertOk();
    }

    public function test_the_ledger_lists_every_movement_with_its_entries(): void
    {
        $client = User::factory()->create();
        $freelancer = User::factory()->create();
        $this->fund($client, 500);
        $this->fund($freelancer, 20);

        $all = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/ledger/transactions')->assertOk();
        $all->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.type', 'deposit');
        $this->assertEqualsCanonicalizing([-20, 20], array_column($all->json('data.0.entries'), 'amount'));
        $this->assertSame(0.0, array_sum(array_column($all->json('data.0.entries'), 'amount')) + 0.0, 'every movement adds up to zero');

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/ledger/transactions?userId={$client->id}")->assertJsonPath('meta.total', 1);
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/ledger/transactions?type=escrow_fund')->assertJsonPath('meta.total', 0);
    }

    public function test_the_trial_balance_shows_the_books_add_up(): void
    {
        $client = User::factory()->create();
        $freelancer = User::factory()->create();
        $this->fund($client, 1000);
        $this->fund($client, 50, 'SAR');

        $balance = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/ledger/trial-balance')->assertOk()->json('data');

        $this->assertSame(['SAR', 'USD'], array_column($balance, 'currency'));
        $this->assertSame([true, true], array_column($balance, 'balanced'));
        $usd = collect($balance)->firstWhere('currency', 'USD')['accounts'];
        $this->assertEquals([['type' => 'gateway', 'accounts' => 1, 'balance' => -1000], ['type' => 'wallet', 'accounts' => 1, 'balance' => 1000]], $usd);
        $this->assertNotNull($freelancer);
    }

    public function test_settings_can_be_read_and_changed(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/settings')->assertOk()->assertExactJson(['data' => [
            'siteFeePercent' => 10, 'depositMin' => 5, 'depositMax' => 10000, 'withdrawalMin' => 20, 'withdrawalFee' => 1, 'withdrawalRequiresKyc' => true,
        ]]);

        $this->actingAs($this->admin, 'sanctum')->putJson('/api/admin/settings', ['siteFeePercent' => 12.5, 'withdrawalRequiresKyc' => false])
            ->assertOk()->assertJsonPath('data.siteFeePercent', 12.5)->assertJsonPath('data.withdrawalRequiresKyc', false)->assertJsonPath('data.depositMin', 5);
        $this->assertSame(12.5, (float) Setting::get('fees.site_percent'));

        foreach ([['siteFeePercent' => 80], ['siteFeePercent' => -1], ['siteFeePercent' => 1.234], ['depositMin' => 50, 'depositMax' => 10], ['withdrawalFee' => -2]] as $bad) {
            $this->actingAs($this->admin, 'sanctum')->putJson('/api/admin/settings', $bad)->assertStatus(422);
        }
        $this->assertSame(12.5, (float) Setting::get('fees.site_percent'), 'refused changes are not saved');
    }

    public function test_a_suspended_account_is_locked_out_with_a_code_the_frontend_can_show(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->getJson('/api/wallet')->assertOk();

        $user->forceFill(['suspended_at' => now()])->save();

        $this->actingAs($user->fresh(), 'sanctum')->getJson('/api/wallet')->assertForbidden()->assertJsonPath('code', 'account_suspended');
        $this->actingAs($user->fresh(), 'sanctum')->getJson('/api/conversations', ['Accept-Language' => 'ar'])->assertJsonPath('message', 'تم تعليق الحساب. تواصل مع الدعم.');
        $this->assertSame(0.0, $this->balance(Account::REVENUE));
    }
}
