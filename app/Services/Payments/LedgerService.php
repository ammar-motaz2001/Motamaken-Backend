<?php

namespace App\Services\Payments;

use App\Exceptions\ApiException;
use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only code that changes a balance. A movement is a list of legs (account, cents) that add up to zero; either
 * all of them are applied, or none. Accounts are locked in id order so two movements cannot deadlock.
 */
class LedgerService
{
    /**
     * A user's wallet in a currency, created on first use.
     */
    public function wallet(User $user, string $currency): Account
    {
        return $this->account(Account::WALLET, "u:{$user->id}", $currency, $user->id);
    }

    /**
     * A platform account (escrow, revenue, gateway, ...) in a currency.
     */
    public function system(string $type, string $currency): Account
    {
        return $this->account($type, 'system', $currency);
    }

    /**
     * @param  list<array{0: Account, 1: int}>  $legs  Positive adds to the account, negative takes from it. Must add up to zero.
     * @param  array{reference?: Model, key?: string, description?: string, meta?: array<string, mixed>, by?: User}  $options
     */
    public function post(string $type, array $legs, array $options = []): LedgerTransaction
    {
        if (array_sum(array_column($legs, 1)) !== 0) {
            throw new \LogicException('A ledger movement must add up to zero.');
        }

        if (isset($options['key']) && $existing = LedgerTransaction::firstWhere('idempotency_key', $options['key'])) {
            return $existing;
        }

        try {
            return DB::transaction(fn () => $this->apply($type, $legs, $options));
        } catch (UniqueConstraintViolationException) {
            // The same key was posted at the very same moment elsewhere: that one counts.
            return LedgerTransaction::where('idempotency_key', $options['key'])->firstOrFail();
        }
    }

    /**
     * @param  list<array{0: Account, 1: int}>  $legs
     * @param  array<string, mixed>  $options
     */
    private function apply(string $type, array $legs, array $options): LedgerTransaction
    {
        $ids = collect($legs)->map(fn (array $leg) => $leg[0]->id)->unique()->sort()->values();
        $locked = Account::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $transaction = LedgerTransaction::create([
            'type' => $type,
            'description' => $options['description'] ?? null,
            'reference_type' => isset($options['reference']) ? $options['reference']->getMorphClass() : null,
            'reference_id' => $options['reference']->id ?? null,
            'meta' => $options['meta'] ?? null,
            'idempotency_key' => $options['key'] ?? null,
            'created_by' => ($options['by'] ?? null)?->id,
        ]);

        foreach ($legs as [$account, $cents]) {
            $locked[$account->id]->balance_cents += $cents;

            if ($locked[$account->id]->balance_cents < 0 && ! $locked[$account->id]->allowsNegative()) {
                throw new ApiException(__('payments.insufficient_funds'), 'insufficient_funds');
            }

            LedgerEntry::create([
                'transaction_id' => $transaction->id,
                'account_id' => $account->id,
                'amount_cents' => $cents,
                'balance_after_cents' => $locked[$account->id]->balance_cents,
            ]);
        }

        $locked->each->save();

        // Callers keep using the Account objects they passed in.
        foreach ($legs as [$account]) {
            $account->balance_cents = $locked[$account->id]->balance_cents;
        }

        return $transaction;
    }

    private function account(string $type, string $ownerKey, string $currency, ?int $userId = null): Account
    {
        $attributes = ['type' => $type, 'owner_key' => $ownerKey, 'currency' => $currency];

        try {
            return Account::firstOrCreate($attributes, ['user_id' => $userId]);
        } catch (UniqueConstraintViolationException) {
            return Account::where($attributes)->firstOrFail();
        }
    }
}
