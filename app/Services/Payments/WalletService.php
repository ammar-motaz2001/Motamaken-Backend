<?php

namespace App\Services\Payments;

use App\Models\Account;
use App\Models\Escrow;
use App\Models\LedgerEntry;
use App\Models\Milestone;
use App\Models\User;
use App\Models\Withdrawal;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read-only views of a user's money: balances, history and the monthly chart.
 */
class WalletService
{
    /**
     * One row per currency the user has money in or is waiting for, always including their preferred currency.
     *
     * @return list<array{currency: string, available: float, inEscrow: float, incoming: float, pendingWithdrawals: float}>
     */
    public function balances(User $user): array
    {
        $available = Account::where('type', Account::WALLET)->where('user_id', $user->id)->pluck('balance_cents', 'currency');

        // Paid by the user into escrow, not yet released.
        $inEscrow = Milestone::query()
            ->whereIn('status', Milestone::HOLDING)
            ->whereHas('escrow', fn (Builder $q) => $q->where('client_id', $user->id))
            ->with('escrow')->get()
            ->groupBy(fn (Milestone $m) => $m->escrow->currency)
            ->map(fn (Collection $milestones) => (int) $milestones->sum('amount_cents'));

        // Held for the user as a payee (their share, before the platform fee).
        $incoming = Milestone::query()
            ->whereIn('status', Milestone::HOLDING)
            ->whereHas('escrow.payees', fn (Builder $q) => $q->where('user_id', $user->id))
            ->with(['escrow.payees'])->get()
            ->groupBy(fn (Milestone $m) => $m->escrow->currency)
            ->map(fn (Collection $milestones) => (int) $milestones->sum(
                fn (Milestone $m) => intdiv($m->amount_cents * $m->escrow->payees->firstWhere('user_id', $user->id)->share_bp, 10000),
            ));

        $pending = Withdrawal::where('user_id', $user->id)->where('status', Withdrawal::PENDING)->get()
            ->groupBy('currency')->map(fn (Collection $w) => (int) $w->sum(fn (Withdrawal $x) => $x->amount_cents + $x->fee_cents));

        return collect([$user->currency])
            ->merge($available->keys())->merge($inEscrow->keys())->merge($incoming->keys())->merge($pending->keys())
            ->unique()->values()
            ->map(fn (string $currency) => [
                'currency' => $currency,
                'available' => Money::fromCents((int) ($available[$currency] ?? 0)),
                'inEscrow' => Money::fromCents((int) ($inEscrow[$currency] ?? 0)),
                'incoming' => Money::fromCents((int) ($incoming[$currency] ?? 0)),
                'pendingWithdrawals' => Money::fromCents((int) ($pending[$currency] ?? 0)),
            ])->all();
    }

    /**
     * The user's wallet history, newest first.
     *
     * @return Builder<LedgerEntry>
     */
    public function history(User $user, ?string $currency = null, ?string $type = null): Builder
    {
        return LedgerEntry::query()
            ->whereHas('account', fn (Builder $q) => $q
                ->where('type', Account::WALLET)->where('user_id', $user->id)
                ->when($currency, fn (Builder $q) => $q->where('currency', $currency)))
            ->when($type, fn (Builder $q) => $q->whereHas('transaction', fn (Builder $t) => $t->where('type', $type)))
            ->with(['transaction.reference', 'account'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Money in and out per month, for the bar chart.
     *
     * @return list<array{month: string, income: float, spending: float}>
     */
    public function monthly(User $user, string $currency, int $months): array
    {
        $start = now()->startOfMonth()->subMonths($months - 1);

        $entries = $this->history($user, $currency)
            ->where('created_at', '>=', $start)
            ->whereHas('transaction', fn (Builder $q) => $q->whereNotIn('type', ['withdrawal_cancelled', 'withdrawal_rejected']))
            ->get(['amount_cents', 'created_at']);

        return collect(range(0, $months - 1))->map(function (int $offset) use ($start, $entries) {
            $month = $start->copy()->addMonths($offset);
            $inMonth = $entries->filter(fn (LedgerEntry $e) => $e->created_at->isSameMonth($month));

            return [
                'month' => $month->format('Y-m'),
                'income' => Money::fromCents((int) $inMonth->where('amount_cents', '>', 0)->sum('amount_cents')),
                'spending' => Money::fromCents(-(int) $inMonth->where('amount_cents', '<', 0)->sum('amount_cents')),
            ];
        })->all();
    }
}
