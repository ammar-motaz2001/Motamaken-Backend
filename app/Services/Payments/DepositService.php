<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Exceptions\ApiException;
use App\Models\Account;
use App\Models\Deposit;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DepositService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly LedgerService $ledger,
    ) {}

    /**
     * Saves the deposit, then starts the payment. With a card that needs no 3-D Secure the outcome is known at once;
     * otherwise `checkout_url` is the provider's page and {@see refresh()} settles it afterwards.
     */
    public function start(User $user, int $cents, string $currency, string $method, ?string $cardToken = null, bool $forPurchase = false): Deposit
    {
        $this->assertWithinLimits($cents, $currency, $forPurchase);

        $deposit = Deposit::create([
            'user_id' => $user->id, 'amount_cents' => $cents, 'currency' => $currency,
            'method' => $method, 'provider' => config('payments.driver'), 'provider_ref' => (string) Str::uuid(),
        ]);

        try {
            $checkout = $this->gateway->createCheckout($deposit, $cardToken);
        } catch (ApiException $e) {
            $this->fail($deposit, $e->getMessage());

            throw $e;
        }

        $deposit->update(['checkout_url' => $checkout['checkoutUrl']]);

        return match ($checkout['status']) {
            'paid' => $this->refresh($deposit),
            'failed' => $this->fail($deposit, $checkout['message'] ?? __('payments.payment_failed')),
            default => $deposit,
        };
    }

    /**
     * Ask the provider how a pending deposit ended and settle it. The wallet is only credited when the provider says
     * it is paid for exactly this amount and currency. Safe to call any number of times.
     */
    public function refresh(Deposit $deposit): Deposit
    {
        if ($deposit->status !== Deposit::PENDING) {
            return $deposit;
        }

        $result = $this->gateway->paymentStatus($deposit);

        if ($result['status'] === 'paid') {
            if ($result['amountCents'] !== $deposit->amount_cents || strtoupper($result['currency']) !== $deposit->currency) {
                Log::error("Deposit {$deposit->id}: provider reports {$result['amountCents']} {$result['currency']}, expected {$deposit->amount_cents} {$deposit->currency}.");

                return $this->fail($deposit, __('payments.amount_mismatch'));
            }

            return $this->succeed($deposit);
        }

        return $result['status'] === 'failed' ? $this->fail($deposit, $result['message'] ?? __('payments.payment_failed')) : $deposit;
    }

    /**
     * The provider confirmed the payment: the money is added to the wallet. Safe to call twice.
     */
    public function succeed(Deposit $deposit): Deposit
    {
        DB::transaction(function () use ($deposit) {
            $locked = Deposit::whereKey($deposit->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== Deposit::PENDING) {
                return;
            }

            $this->ledger->post('deposit', [
                [$this->ledger->system(Account::GATEWAY, $locked->currency), -$locked->amount_cents],
                [$this->ledger->wallet($locked->user, $locked->currency), $locked->amount_cents],
            ], ['reference' => $locked, 'key' => "deposit:{$locked->id}", 'meta' => ['method' => $locked->method]]);

            $locked->update(['status' => Deposit::SUCCEEDED, 'paid_at' => now()]);

            $locked->user->notify(new ActivityNotification('deposit.succeeded', null, [
                'amount' => Money::fromCents($locked->amount_cents), 'currency' => $locked->currency,
            ]));
        });

        return $deposit->refresh();
    }

    /**
     * Mark a pending deposit as failed with the provider's reason. Nothing reaches the wallet; finished deposits are left as they are.
     */
    public function fail(Deposit $deposit, string $reason): Deposit
    {
        if ($deposit->status === Deposit::PENDING) {
            $deposit->update(['status' => Deposit::FAILED, 'failure_reason' => Str::limit($reason, 250)]);
        }

        return $deposit;
    }

    /**
     * Refuse an amount outside the deposit minimum and maximum from the settings. Topping up for a purchase only needs
     * at least 1 unit.
     */
    private function assertWithinLimits(int $cents, string $currency, bool $forPurchase = false): void
    {
        $min = $forPurchase ? 1.0 : (float) Setting::get('deposits.min');
        $max = (float) Setting::get('deposits.max');

        if ($cents < Money::toCents($min)) {
            throw new ApiException(__('payments.amount_too_small', ['min' => $min, 'currency' => $currency]), 'amount_too_small');
        }

        if ($cents > Money::toCents($max)) {
            throw new ApiException(__('payments.amount_too_large', ['max' => $max, 'currency' => $currency]), 'amount_too_large');
        }
    }
}
