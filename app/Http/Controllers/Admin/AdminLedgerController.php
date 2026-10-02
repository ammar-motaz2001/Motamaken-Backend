<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\LedgerTransaction;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminLedgerController extends Controller
{
    /**
     * Ledger transactions.
     *
     * The double-entry book: every movement of money with its entries (account, amount, balance after), newest first.
     * Filter by `type` (e.g. `escrow_release`) or by `userId` (movements touching that user's wallet).
     *
     * @return array{data: list<array{id: int, type: string, description: ?string, reference: ?array{type: string, id: int}, meta: ?array<string, mixed>, entries: list<array{account: array{id: int, type: string, currency: string, userId: ?int}, amount: float, balanceAfter: float}>, createdAt: string}>, links: array<string, mixed>, meta: array<string, mixed>}
     */
    public function transactions(Request $request): array
    {
        $request->validate([
            'type' => ['nullable', 'string', 'max:40'],
            'userId' => ['nullable', 'integer'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = LedgerTransaction::query()
            ->with(['entries.account'])
            ->when($request->input('type'), fn ($query, $type) => $query->where('type', $type))
            ->when($request->input('userId'), fn ($query, $user) => $query->whereHas('entries.account', fn ($q) => $q->where('user_id', $user)))
            ->latest('id')
            ->paginate($request->integer('perPage', 20));

        return [
            'data' => $page->getCollection()->map(fn (LedgerTransaction $t) => [
                'id' => $t->id,
                'type' => $t->type,
                'description' => $t->description,
                'reference' => $t->reference_id ? ['type' => class_basename($t->reference_type), 'id' => $t->reference_id] : null,
                'meta' => $t->meta,
                'entries' => $t->entries->map(fn ($e) => [
                    'account' => ['id' => $e->account->id, 'type' => $e->account->type, 'currency' => $e->account->currency, 'userId' => $e->account->user_id],
                    'amount' => $e->amount_cents / 100,
                    'balanceAfter' => $e->balance_after_cents / 100,
                ])->all(),
                'createdAt' => $t->created_at->toIso8601String(),
            ])->all(),
            'links' => ['first' => $page->url(1), 'last' => $page->url($page->lastPage()), 'prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()],
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ];
    }

    /**
     * Trial balance.
     *
     * Every account type per currency. In a correct double-entry book the balances of all accounts add up to zero
     * (`balanced`): the gateway account is negative by exactly the money the platform holds for users and itself.
     *
     * @return array{data: list<array{currency: string, balanced: bool, accounts: list<array{type: string, accounts: int, balance: float}>}>}
     */
    public function trialBalance(): array
    {
        $rows = Account::query()
            ->selectRaw('currency, type, count(*) as accounts, sum(balance_cents) as balance')
            ->groupBy('currency', 'type')->orderBy('currency')->orderBy('type')->get()
            ->groupBy('currency');

        return ['data' => $rows->map(fn ($types, string $currency) => [
            'currency' => $currency,
            'balanced' => (int) $types->sum('balance') === 0,
            'accounts' => $types->map(fn ($row) => [
                'type' => $row->type, 'accounts' => (int) $row->accounts, 'balance' => (int) $row->balance / 100,
            ])->values()->all(),
        ])->values()->all()];
    }
}
