<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Services\Payments\DepositService;
use App\Support\Money;
use App\Support\Paged;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminDepositController extends Controller
{
    public function __construct(private readonly DepositService $deposits) {}

    /**
     * Wallet top-ups.
     *
     * Every attempt to add money, successful, failed or still waiting for the payment provider, with the provider's
     * reference. Newest first, with counts and totals per status.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>, counts: array<string, int>}
     */
    public function index(Request $request): array
    {
        $request->validate([
            /** `pending`, `succeeded` or `failed`. */
            'status' => ['nullable', Rule::in([Deposit::PENDING, Deposit::SUCCEEDED, Deposit::FAILED])],
            'userId' => ['nullable', 'integer'],
            /** The provider's payment id or our reference. */
            'reference' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->filtered($request)->with('user')->latest('id')->paginate($request->integer('perPage', 20));

        return Paged::of($page, fn (Deposit $d) => self::present($d), [
            'counts' => Deposit::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n)->all(),
        ]);
    }

    /**
     * The deposits query behind the list, shared with the CSV export.
     *
     * @return Builder<Deposit>
     */
    public function filtered(Request $request): Builder
    {
        return Deposit::query()
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('userId'), fn ($q, $id) => $q->where('user_id', $id))
            ->when($request->input('reference'), fn ($q, $ref) => $q->where(fn ($w) => $w->where('provider_ref', 'like', '%'.addcslashes($ref, '%_\\').'%')
                ->when(ctype_digit($ref), fn ($w) => $w->orWhere('id', (int) $ref))))
            ->when($request->input('from'), fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($request->input('to'), fn ($q, $d) => $q->where('created_at', '<', now()->parse($d)->addDay()));
    }

    /**
     * Check a top-up with the provider.
     *
     * Asks the payment provider about a pending top-up and credits or fails it accordingly (the same check the webhook
     * and the return page do).
     *
     * @return array{data: array<string, mixed>}
     */
    public function refresh(int $deposit): array
    {
        $deposit = $this->deposits->refresh(Deposit::findOrFail($deposit));

        return ['data' => self::present($deposit->load('user'))];
    }

    /**
     * A deposit for the admin list, with the gateway details.
     *
     * @return array<string, mixed>
     */
    public static function present(Deposit $d): array
    {
        return [
            'id' => $d->id,
            'user' => Paged::user($d->user),
            'amount' => Money::fromCents($d->amount_cents),
            'currency' => $d->currency,
            'method' => $d->method,
            'provider' => $d->provider,
            'providerRef' => $d->provider_ref,
            'status' => $d->status,
            'failureReason' => $d->failure_reason,
            'paidAt' => $d->paid_at?->toIso8601String(),
            'createdAt' => $d->created_at?->toIso8601String(),
        ];
    }
}
