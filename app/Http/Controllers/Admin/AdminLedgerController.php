<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Escrow;
use App\Models\LedgerTransaction;
use App\Models\Milestone;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
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
            /** Transactions of one project: its escrow funding, releases, refunds, tips and dispute settlements. */
            'projectId' => ['nullable', 'integer'],
            /** A transaction number, an idempotency key, or text from the description (e.g. a payout reference). */
            'reference' => ['nullable', 'string', 'max:100'],
            'currency' => ['nullable', 'string', 'size:3'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->filtered($request)
            ->with(['entries.account', 'creator'])
            ->latest('id')
            ->paginate($request->integer('perPage', 20));

        return [
            'data' => $page->getCollection()->map(fn (LedgerTransaction $t) => [
                'id' => $t->id,
                'type' => $t->type,
                'description' => $t->description,
                'reference' => $t->reference_id ? ['type' => class_basename($t->reference_type), 'id' => $t->reference_id] : null,
                'meta' => $t->meta,
                'createdBy' => $t->creator ? ['id' => $t->creator->id, 'fullName' => $t->creator->full_name] : null,
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
     * The transactions query behind the list, shared with the CSV export.
     *
     * @return Builder<LedgerTransaction>
     */
    public function filtered(Request $request)
    {
        return LedgerTransaction::query()
            ->when($request->input('type'), fn ($query, $type) => $query->where('type', $type))
            ->when($request->input('userId'), fn ($query, $user) => $query->whereHas('entries.account', fn ($q) => $q->where('user_id', $user)))
            ->when($request->input('currency'), fn ($query, $c) => $query->whereHas('entries.account', fn ($q) => $q->where('currency', strtoupper($c))))
            ->when($request->input('from'), fn ($query, $d) => $query->where('created_at', '>=', $d))
            ->when($request->input('to'), fn ($query, $d) => $query->where('created_at', '<', now()->parse($d)->addDay()))
            ->when($request->input('projectId'), function ($query, $projectId) {
                $escrow = Escrow::where('project_id', $projectId)->first();
                $milestones = $escrow ? Milestone::where('escrow_id', $escrow->id)->pluck('id') : collect();

                $query->where(fn ($q) => $q
                    ->where(fn ($m) => $m->where('reference_type', (new Milestone)->getMorphClass())->whereIn('reference_id', $milestones))
                    ->orWhere(fn ($e) => $e->where('reference_type', (new Escrow)->getMorphClass())->where('reference_id', $escrow?->id ?? 0))
                    ->orWhere('meta->projectId', (string) $projectId));
            })
            ->when($request->input('reference'), fn ($query, $ref) => $query->where(fn ($q) => $q
                ->where('idempotency_key', $ref)
                ->orWhere('description', 'like', '%'.addcslashes($ref, '%_\\').'%')
                ->when(ctype_digit($ref), fn ($q) => $q->orWhere('id', (int) $ref)->orWhere('reference_id', (int) $ref))));
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
