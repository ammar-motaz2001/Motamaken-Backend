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

class DepositService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly LedgerService $ledger,
    ) {}

    public function start(User $user, int $cents, string $currency, string $method): Deposit
    {
        $this->assertWithinLimits($cents, $currency);

        $deposit = new Deposit([
            'user_id' => $user->id, 'amount_cents' => $cents, 'currency' => $currency,
            'method' => $method, 'provider' => config('payments.driver'),
        ]);

        $checkout = $this->gateway->createCheckout($deposit);
        $deposit->fill(['provider_ref' => $checkout['ref'], 'checkout_url' => $checkout['checkoutUrl']])->save();

        return $deposit;
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

    public function fail(Deposit $deposit, string $reason): Deposit
    {
        if ($deposit->status === Deposit::PENDING) {
            $deposit->update(['status' => Deposit::FAILED, 'failure_reason' => $reason]);
        }

        return $deposit;
    }

    private function assertWithinLimits(int $cents, string $currency): void
    {
        $min = (float) Setting::get('deposits.min');
        $max = (float) Setting::get('deposits.max');

        if ($cents < Money::toCents($min)) {
            throw new ApiException(__('payments.amount_too_small', ['min' => $min, 'currency' => $currency]), 'amount_too_small');
        }

        if ($cents > Money::toCents($max)) {
            throw new ApiException(__('payments.amount_too_large', ['max' => $max, 'currency' => $currency]), 'amount_too_large');
        }
    }
}
