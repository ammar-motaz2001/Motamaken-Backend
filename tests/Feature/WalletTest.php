<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Deposit;
use App\Models\Setting;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\HandlesMoney;
use Tests\TestCase;

class WalletTest extends TestCase
{
    use HandlesMoney, RefreshDatabase;

    private User $user;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['kyc_status' => 'approved']);
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    // ---- deposits -------------------------------------------------------------------------------------------------

    public function test_a_deposit_is_only_credited_when_the_provider_confirms_it(): void
    {
        $deposit = $this->as($this->user)->postJson('/api/wallet/deposits', ['amount' => 50.5, 'currency' => 'USD', 'method' => 'card'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', 50.5)
            ->assertJsonPath('data.checkoutUrl', fn ($url) => str_contains($url, '/fake-checkout/'))
            ->json('data');

        $this->assertSame(0.0, $this->balance($this->user));

        $this->confirm($deposit['id'], 'succeeded')->assertOk()->assertExactJson(['received' => true]);
        $this->assertSame(50.5, $this->balance($this->user));
        $this->assertSame(-50.5, $this->balance(Account::GATEWAY));
        $this->assertBooksBalance();

        // The provider retries: the money is not credited again.
        $this->confirm($deposit['id'], 'succeeded')->assertOk();
        $this->assertSame(50.5, $this->balance($this->user));

        $this->as($this->user)->getJson('/api/notifications')->assertJsonPath('data.0.type', 'deposit.succeeded')
            ->assertJsonPath('data.0.message', 'Your deposit of 50.5 USD was added to your wallet.');
    }

    public function test_a_failed_payment_adds_nothing_and_a_forged_webhook_is_refused(): void
    {
        $id = $this->as($this->user)->postJson('/api/wallet/deposits', ['amount' => 20, 'currency' => 'USD', 'method' => 'mada'])->json('data.id');
        $ref = Deposit::find($id)->provider_ref;

        $this->postJson('/api/webhooks/payments', ['ref' => $ref, 'status' => 'succeeded'], ['X-Signature' => 'forged'])->assertForbidden();
        $this->postJson('/api/webhooks/payments', ['ref' => $ref, 'status' => 'succeeded'])->assertForbidden();
        $this->assertSame(0.0, $this->balance($this->user));

        $this->confirm($id, 'failed')->assertOk();
        $this->assertSame('failed', Deposit::find($id)->status);
        $this->confirm($id, 'succeeded')->assertOk();
        $this->assertSame(0.0, $this->balance($this->user), 'a failed payment cannot be revived');

        $this->signed(['ref' => 'unknown', 'status' => 'succeeded'])->assertNotFound();
        $this->signed(['ref' => $ref, 'status' => 'maybe'])->assertStatus(422);
    }

    public function test_deposit_validation_and_limits(): void
    {
        $post = fn (array $data) => $this->as($this->user)->postJson('/api/wallet/deposits', ['amount' => 10, 'currency' => 'USD', 'method' => 'card', ...$data]);

        $post(['amount' => 1])->assertStatus(422)->assertJsonPath('code', 'amount_too_small')->assertJsonPath('message', 'The minimum is 5 USD.');
        $post(['amount' => 20000])->assertStatus(422)->assertJsonPath('code', 'amount_too_large');
        $post(['amount' => 10.555])->assertJsonValidationErrors('amount');
        $post(['amount' => -5])->assertJsonValidationErrors('amount');
        $post(['currency' => 'XXX'])->assertJsonValidationErrors('currency');
        $post(['method' => 'cash'])->assertJsonValidationErrors('method');

        Setting::put('deposits.min', 1);
        $post(['amount' => 1])->assertCreated();
        $this->assertDatabaseCount('deposits', 1);
    }

    public function test_the_fake_gateway_can_be_completed_by_hand_in_development_only(): void
    {
        $id = $this->as($this->user)->postJson('/api/wallet/deposits', ['amount' => 30, 'currency' => 'SAR', 'method' => 'card'])->json('data.id');

        $this->as(User::factory()->create())->postJson("/api/wallet/deposits/{$id}/simulate")->assertNotFound();

        $this->as($this->user)->postJson("/api/wallet/deposits/{$id}/simulate")->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->assertSame(30.0, $this->balance($this->user, 'SAR'));
        $this->as($this->user)->postJson("/api/wallet/deposits/{$id}/simulate")->assertStatus(422)->assertJsonPath('code', 'deposit_not_pending');

        $this->as($this->user)->getJson('/api/wallet/deposits')->assertJsonPath('data.0.checkoutUrl', null)->assertJsonPath('meta.total', 1);

        config(['payments.driver' => 'stripe']);
        $other = $this->as($this->user)->postJson('/api/wallet/deposits', ['amount' => 10, 'currency' => 'USD', 'method' => 'card'])->json('data.id');
        $this->as($this->user)->postJson("/api/wallet/deposits/{$other}/simulate")->assertNotFound();
    }

    // ---- balances and history -------------------------------------------------------------------------------------

    public function test_balances_history_and_the_monthly_chart(): void
    {
        $this->fund($this->user, 100);
        $this->travel(-40)->days();
        $this->fund($this->user, 25, 'SAR');
        $this->travelBack();

        $wallet = $this->as($this->user)->getJson('/api/wallet')->assertOk()->json('data');
        $this->assertEquals(
            [['currency' => 'USD', 'available' => 100, 'inEscrow' => 0, 'incoming' => 0, 'pendingWithdrawals' => 0],
                ['currency' => 'SAR', 'available' => 25, 'inEscrow' => 0, 'incoming' => 0, 'pendingWithdrawals' => 0]],
            $wallet,
        );

        $this->as($this->user)->getJson('/api/wallet/transactions')->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.label', 'Deposit')->assertJsonPath('data.0.amount', 100)->assertJsonPath('data.0.balanceAfter', 100);
        $this->as($this->user)->getJson('/api/wallet/transactions?currency=SAR')->assertJsonPath('meta.total', 1);
        $this->as($this->user)->getJson('/api/wallet/transactions?type=escrow_fund')->assertJsonPath('meta.total', 0);
        $this->as($this->user)->getJson('/api/wallet/transactions?currency=XXX')->assertStatus(422);
        $this->as($this->user)->getJson('/api/wallet/transactions', ['Accept-Language' => 'ar'])->assertJsonPath('data.0.label', 'إيداع');

        $stats = $this->as($this->user)->getJson('/api/wallet/stats?months=3')->assertOk()->json();
        $this->assertSame('USD', $stats['currency']);
        $this->assertCount(3, $stats['data']);
        $this->assertSame(['income' => 100, 'spending' => 0], collect($stats['data'])->last() ? ['income' => collect($stats['data'])->last()['income'], 'spending' => collect($stats['data'])->last()['spending']] : []);
        $this->as($this->user)->getJson('/api/wallet/stats?months=13')->assertStatus(422);
    }

    public function test_other_peoples_money_is_not_visible(): void
    {
        $this->fund($this->user, 100);

        $this->as(User::factory()->create())->getJson('/api/wallet/transactions')->assertJsonPath('data', []);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/wallet')->assertUnauthorized();
    }

    // ---- withdrawals ----------------------------------------------------------------------------------------------

    public function test_withdrawing_needs_a_verified_identity_and_enough_money(): void
    {
        $unverified = User::factory()->create();
        $this->fund($unverified, 100);
        $this->fund($this->user, 100);

        $this->as($unverified)->postJson('/api/wallet/withdrawals', $this->withdrawal())->assertStatus(422)->assertJsonPath('code', 'kyc_required');
        $this->as($this->user)->postJson('/api/wallet/withdrawals', $this->withdrawal(['amount' => 5]))->assertJsonPath('code', 'amount_too_small');
        $this->as($this->user)->postJson('/api/wallet/withdrawals', $this->withdrawal(['amount' => 100]))
            ->assertStatus(422)->assertJsonPath('code', 'insufficient_funds');
        $this->assertSame(100.0, $this->balance($this->user), 'a refused withdrawal keeps the money');

        Setting::put('withdrawals.require_kyc', false);
        $this->as($unverified)->postJson('/api/wallet/withdrawals', $this->withdrawal())->assertCreated();
    }

    public function test_a_withdrawal_reserves_the_money_then_is_paid_out_by_an_admin(): void
    {
        $this->fund($this->user, 100);

        $withdrawal = $this->as($this->user)->postJson('/api/wallet/withdrawals', $this->withdrawal(['amount' => 60]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.fee', 1)
            ->assertJsonPath('data.destination', '…7519')
            ->json('data');

        $this->assertSame(39.0, $this->balance($this->user), '60 + 1 fee is set aside');
        $this->as($this->user)->getJson('/api/wallet')->assertJsonPath('data.0.pendingWithdrawals', 61);
        $this->assertStringNotContainsString('SA0380000000608010167519', DB::table('withdrawals')->value('destination'), 'the bank account is stored encrypted');

        $this->as($this->user)->getJson('/api/admin/withdrawals')->assertForbidden();
        $this->as($this->admin)->getJson('/api/admin/withdrawals')->assertOk()->assertJsonPath('data.0.id', $withdrawal['id'])
            ->assertJsonPath('data.0.user.id', $this->user->id);

        $this->as($this->admin)->postJson("/api/admin/withdrawals/{$withdrawal['id']}/approve", ['reference' => 'TRX-9981'])
            ->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.payoutReference', 'TRX-9981');

        $this->assertSame(39.0, $this->balance($this->user));
        $this->assertSame(1.0, $this->balance(Account::REVENUE), 'the fee is revenue');
        $this->assertSame(0.0, $this->balance(Account::WITHDRAWALS));
        $this->assertSame(-40.0, $this->balance(Account::GATEWAY), '100 came in, 60 went out');
        $this->assertBooksBalance();

        $this->as($this->admin)->postJson("/api/admin/withdrawals/{$withdrawal['id']}/approve")->assertStatus(422)->assertJsonPath('code', 'withdrawal_not_pending');
        $this->as($this->user)->postJson("/api/wallet/withdrawals/{$withdrawal['id']}/cancel")->assertStatus(422);
        $this->as($this->user)->getJson('/api/notifications')->assertJsonPath('data.0.message', 'Your withdrawal of 60 USD was paid out.');
    }

    public function test_a_rejected_or_cancelled_withdrawal_returns_everything(): void
    {
        $this->fund($this->user, 100);
        $first = $this->as($this->user)->postJson('/api/wallet/withdrawals', $this->withdrawal(['amount' => 30]))->json('data.id');
        $second = $this->as($this->user)->postJson('/api/wallet/withdrawals', $this->withdrawal(['amount' => 20]))->json('data.id');
        $this->assertSame(48.0, $this->balance($this->user), 'each request sets aside its amount and its own fee');

        $this->as($this->admin)->postJson("/api/admin/withdrawals/{$first}/reject", [])->assertJsonValidationErrors('reason');
        $this->as($this->admin)->postJson("/api/admin/withdrawals/{$first}/reject", ['reason' => 'Name does not match'])
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejectReason', 'Name does not match');
        $this->assertSame(79.0, $this->balance($this->user));

        $stranger = User::factory()->create(['kyc_status' => 'approved']);
        $this->as($stranger)->postJson("/api/wallet/withdrawals/{$second}/cancel")->assertNotFound();
        $this->as($this->user)->postJson("/api/wallet/withdrawals/{$second}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(100.0, $this->balance($this->user));
        $this->assertSame(0.0, $this->balance(Account::REVENUE), 'no fee is kept when nothing was paid out');
        $this->assertBooksBalance();
        $this->as($this->user)->getJson('/api/notifications')->assertJsonPath('data.0.message', 'Your withdrawal of 30 USD was rejected: Name does not match. The money is back in your wallet.');
    }

    public function test_withdrawal_validation(): void
    {
        $this->fund($this->user, 500);
        $post = fn (array $override) => $this->as($this->user)->postJson('/api/wallet/withdrawals', $this->withdrawal($override));

        $this->as($this->user)->postJson('/api/wallet/withdrawals', [])->assertJsonValidationErrors(['amount', 'currency', 'method', 'destination']);
        $post(['destination' => ['accountName' => 'Ammar']])->assertJsonValidationErrors('destination.iban');
        $post(['destination' => ['iban' => 'SA0380000000608010167519']])->assertJsonValidationErrors('destination.accountName');
        $post(['method' => 'paypal', 'destination' => []])->assertJsonValidationErrors('destination.email');
        $post(['method' => 'paypal', 'destination' => ['email' => 'me@example.com']])->assertCreated();
        $post(['method' => 'crypto'])->assertJsonValidationErrors('method');
        $this->assertSame(1, Withdrawal::count());
    }

    // ---- helpers --------------------------------------------------------------------------------------------------

    private function as(User $user): static
    {
        return $this->actingAs($user, 'sanctum');
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function withdrawal(array $override = []): array
    {
        return [
            'amount' => 60, 'currency' => 'USD', 'method' => 'bank_transfer',
            'destination' => ['accountName' => 'Ammar Motaz', 'iban' => 'SA0380000000608010167519'],
            ...$override,
        ];
    }

    private function confirm(int $depositId, string $status): TestResponse
    {
        return $this->signed(['ref' => Deposit::find($depositId)->provider_ref, 'status' => $status]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function signed(array $body): TestResponse
    {
        $raw = json_encode($body);

        return $this->call('POST', '/api/webhooks/payments', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $raw, config('payments.webhook_secret')),
        ], $raw);
    }
}
