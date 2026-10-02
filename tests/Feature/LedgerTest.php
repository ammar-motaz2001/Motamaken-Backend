<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Services\Payments\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HandlesMoney;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use HandlesMoney, RefreshDatabase;

    private LedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = app(LedgerService::class);
    }

    public function test_a_movement_changes_balances_and_records_every_entry(): void
    {
        $user = User::factory()->create();
        $gateway = $this->ledger->system(Account::GATEWAY, 'USD');
        $wallet = $this->ledger->wallet($user, 'USD');

        $transaction = $this->ledger->post('deposit', [[$gateway, -2500], [$wallet, 2500]], ['description' => 'test']);

        $this->assertSame(2500, $wallet->refresh()->balance_cents);
        $this->assertSame(-2500, $gateway->refresh()->balance_cents, 'the gateway mirrors the money that came in');
        $this->assertCount(2, $transaction->entries);
        $this->assertSame([2500, -2500], $transaction->entries->sortByDesc('amount_cents')->pluck('amount_cents')->values()->all());
        $this->assertSame(2500, LedgerEntry::where('account_id', $wallet->id)->value('balance_after_cents'));
        $this->assertBooksBalance();
    }

    public function test_a_movement_that_does_not_add_up_is_refused(): void
    {
        $wallet = $this->ledger->wallet(User::factory()->create(), 'USD');

        $this->expectException(\LogicException::class);
        $this->ledger->post('deposit', [[$this->ledger->system(Account::GATEWAY, 'USD'), -100], [$wallet, 150]]);
    }

    public function test_money_cannot_be_spent_twice_and_a_failed_movement_changes_nothing(): void
    {
        [$alice, $bob] = User::factory()->count(2)->create();
        $this->fund($alice, 10);
        $aliceWallet = $this->ledger->wallet($alice, 'USD');
        $bobWallet = $this->ledger->wallet($bob, 'USD');
        $revenue = $this->ledger->system(Account::REVENUE, 'USD');

        try {
            // Bob gets paid first in the list, then Alice's wallet would go below zero.
            $this->ledger->post('adjustment', [[$bobWallet, 2000], [$revenue, 100], [$aliceWallet, -2100]]);
            $this->fail('the movement should have been refused');
        } catch (ApiException $e) {
            $this->assertSame('Your wallet balance is not enough for this.', $e->getMessage());
        }

        $this->assertSame(1000, $aliceWallet->refresh()->balance_cents);
        $this->assertSame(0, $bobWallet->refresh()->balance_cents);
        $this->assertSame(0, $revenue->refresh()->balance_cents);
        $this->assertSame(1, LedgerTransaction::count(), 'only the funding deposit exists');
        $this->assertBooksBalance();
    }

    public function test_the_same_idempotency_key_posts_once(): void
    {
        $user = User::factory()->create();
        $legs = fn () => [[$this->ledger->system(Account::GATEWAY, 'USD'), -500], [$this->ledger->wallet($user, 'USD'), 500]];

        $first = $this->ledger->post('deposit', $legs(), ['key' => 'dep:1']);
        $second = $this->ledger->post('deposit', $legs(), ['key' => 'dep:1']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(5.0, $this->balance($user));
        $this->assertSame(1, LedgerTransaction::count());
    }

    public function test_wallets_are_per_user_and_per_currency(): void
    {
        $user = User::factory()->create();

        $this->fund($user, 10, 'USD');
        $this->fund($user, 20, 'SAR');

        $this->assertSame(10.0, $this->balance($user, 'USD'));
        $this->assertSame(20.0, $this->balance($user, 'SAR'));
        $this->assertSame($this->ledger->wallet($user, 'USD')->id, $this->ledger->wallet($user, 'USD')->id, 'asking twice gives the same wallet');
        $this->assertSame(2, Account::where('type', Account::WALLET)->count());
    }
}
