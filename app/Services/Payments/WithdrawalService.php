<?php

namespace App\Services\Payments;

use App\Exceptions\ApiException;
use App\Models\Account;
use App\Models\Setting;
use App\Models\User;
use App\Models\Withdrawal;
use App\Notifications\ActivityNotification;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class WithdrawalService
{
    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * The amount plus the fee is set aside from the wallet right away, so it cannot be spent twice.
     *
     * @param  array<string, mixed>  $destination  Bank / PayPal details, stored encrypted.
     */
    public function request(User $user, int $cents, string $currency, string $method, array $destination): Withdrawal
    {
        if (Setting::get('withdrawals.require_kyc') && $user->kyc_status !== 'approved') {
            throw new ApiException(__('payments.kyc_required'), 'kyc_required');
        }

        $min = (float) Setting::get('withdrawals.min');

        if ($cents < Money::toCents($min)) {
            throw new ApiException(__('payments.amount_too_small', ['min' => $min, 'currency' => $currency]), 'amount_too_small');
        }

        $fee = Money::toCents((float) Setting::get('withdrawals.fee'));

        return DB::transaction(function () use ($user, $cents, $currency, $method, $destination, $fee) {
            $withdrawal = Withdrawal::create([
                'user_id' => $user->id, 'amount_cents' => $cents, 'fee_cents' => $fee,
                'currency' => $currency, 'method' => $method, 'destination' => $destination,
            ]);

            $this->ledger->post('withdrawal_request', [
                [$this->ledger->wallet($user, $currency), -($cents + $fee)],
                [$this->ledger->system(Account::WITHDRAWALS, $currency), $cents + $fee],
            ], ['reference' => $withdrawal, 'key' => "withdrawal:{$withdrawal->id}:request"]);

            return $withdrawal;
        });
    }

    /**
     * The user takes the request back while nobody has answered it: the money returns to the wallet.
     */
    public function cancel(Withdrawal $withdrawal): Withdrawal
    {
        return $this->release($withdrawal, Withdrawal::CANCELLED, 'withdrawal_cancelled');
    }

    /**
     * An admin paid the money out by bank transfer / PayPal.
     */
    public function markPaid(Withdrawal $withdrawal, User $admin, ?string $reference): Withdrawal
    {
        DB::transaction(function () use ($withdrawal, $admin, $reference) {
            $locked = $this->lock($withdrawal);

            $this->ledger->post('withdrawal_paid', [
                [$this->ledger->system(Account::WITHDRAWALS, $locked->currency), -($locked->amount_cents + $locked->fee_cents)],
                [$this->ledger->system(Account::GATEWAY, $locked->currency), $locked->amount_cents],
                [$this->ledger->system(Account::REVENUE, $locked->currency), $locked->fee_cents],
            ], ['reference' => $locked, 'key' => "withdrawal:{$locked->id}:paid", 'by' => $admin]);

            $locked->update([
                'status' => Withdrawal::PAID, 'payout_reference' => $reference,
                'reviewed_by' => $admin->id, 'reviewed_at' => now(),
            ]);

            $locked->user->notify(new ActivityNotification('withdrawal.paid', null, [
                'amount' => Money::fromCents($locked->amount_cents), 'currency' => $locked->currency, 'withdrawalId' => $locked->id,
            ]));
        });

        return $withdrawal->refresh();
    }

    /**
     * An admin refuses a pending request with a reason: the money set aside returns to the wallet and the user is told.
     */
    public function reject(Withdrawal $withdrawal, User $admin, string $reason): Withdrawal
    {
        $this->release($withdrawal, Withdrawal::REJECTED, 'withdrawal_rejected', $admin, $reason);

        return $withdrawal->refresh();
    }

    /**
     * Return the amount and fee set aside for a pending request to the wallet and close it with the given status.
     */
    private function release(Withdrawal $withdrawal, string $status, string $type, ?User $admin = null, ?string $reason = null): Withdrawal
    {
        DB::transaction(function () use ($withdrawal, $status, $type, $admin, $reason) {
            $locked = $this->lock($withdrawal);

            $this->ledger->post($type, [
                [$this->ledger->system(Account::WITHDRAWALS, $locked->currency), -($locked->amount_cents + $locked->fee_cents)],
                [$this->ledger->wallet($locked->user, $locked->currency), $locked->amount_cents + $locked->fee_cents],
            ], ['reference' => $locked, 'key' => "withdrawal:{$locked->id}:{$status}", 'by' => $admin]);

            $locked->update([
                'status' => $status, 'reject_reason' => $reason,
                'reviewed_by' => $admin?->id, 'reviewed_at' => $admin ? now() : null,
            ]);

            if ($status === Withdrawal::REJECTED) {
                $locked->user->notify(new ActivityNotification('withdrawal.rejected', null, [
                    'amount' => Money::fromCents($locked->amount_cents), 'currency' => $locked->currency,
                    'reason' => $reason, 'withdrawalId' => $locked->id,
                ]));
            }
        });

        return $withdrawal->refresh();
    }

    /**
     * Re-read the request under a row lock, refusing it once it is no longer pending, so it is never paid and refunded both.
     */
    private function lock(Withdrawal $withdrawal): Withdrawal
    {
        $locked = Withdrawal::whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isPending()) {
            throw new ApiException(__('payments.withdrawal_not_pending'), 'withdrawal_not_pending');
        }

        return $locked;
    }
}
