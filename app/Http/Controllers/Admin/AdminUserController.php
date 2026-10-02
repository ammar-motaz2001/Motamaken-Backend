<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminUserResource;
use App\Models\Account;
use App\Models\Dispute;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use App\Services\Payments\LedgerService;
use App\Services\Payments\WalletService;
use App\Support\LikeSearch;
use App\Support\Money;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminUserController extends Controller
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly LedgerService $ledger,
    ) {}

    /**
     * List users.
     *
     * Search by name, username, email or phone, and filter by state. Newest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            /** `active` or `suspended`. */
            'status' => ['nullable', Rule::in(['active', 'suspended'])],
            'kyc' => ['nullable', Rule::in(['none', 'pending', 'approved', 'rejected'])],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return AdminUserResource::collection(
            User::query()
                ->when($request->input('q'), fn ($query, $term) => LikeSearch::apply($query, [
                    'first_name', 'last_name', 'username', 'email', 'phone',
                ], $term))
                ->when($request->input('status') === 'suspended', fn ($query) => $query->whereNotNull('suspended_at'))
                ->when($request->input('status') === 'active', fn ($query) => $query->whereNull('suspended_at'))
                ->when($request->input('kyc'), fn ($query, $kyc) => $query->where('kyc_status', $kyc))
                ->latest()->latest('id')
                ->paginate($request->integer('perPage', 20)),
        );
    }

    /**
     * Show a user.
     *
     * With their numbers and wallet balances.
     *
     * @return array{data: array<string, mixed>, wallet: list<array{currency: string, available: float, inEscrow: float, incoming: float, pendingWithdrawals: float}>}
     */
    public function show(int $user): array
    {
        $user = User::findOrFail($user);
        $user->stats = [
            'projects' => Project::where('user_id', $user->id)->count(),
            'offers' => Proposal::where('user_id', $user->id)->count(),
            'openDisputes' => Dispute::whereIn('status', Dispute::ACTIVE)->where(fn ($q) => $q->where('opened_by', $user->id)->orWhere('against_id', $user->id))->count(),
        ];

        return ['data' => (new AdminUserResource($user))->resolve(), 'wallet' => $this->wallet->balances($user)];
    }

    /**
     * Suspend a user.
     *
     * Locks the account out at once (every call answers `account_suspended`) and ends their sessions. Money and projects stay
     * as they are. Admins cannot be suspended.
     */
    public function suspend(Request $request, int $user): AdminUserResource
    {
        $data = $request->validate([
            /** Kept in the record and shown to the user. */
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $target = User::findOrFail($user);

        if ($target->is_admin) {
            throw new ApiException(__('admin.cannot_suspend_admin'), 'cannot_suspend_admin');
        }

        $target->forceFill(['suspended_at' => now(), 'suspension_reason' => $data['reason']])->save();
        $target->tokens()->delete();

        return new AdminUserResource($target);
    }

    /**
     * Lift a suspension.
     */
    public function unsuspend(int $user): AdminUserResource
    {
        $target = User::findOrFail($user);
        $target->forceFill(['suspended_at' => null, 'suspension_reason' => null])->save();

        return new AdminUserResource($target);
    }

    /**
     * Adjust a wallet.
     *
     * Support tool: adds money (positive `amount`) or takes it (negative) with a mandatory reason. It is a normal ledger
     * movement against the gateway account, recorded under your name, and cannot take a wallet below zero.
     *
     * @return array{data: list<array{currency: string, available: float, inEscrow: float, incoming: float, pendingWithdrawals: float}>}
     */
    public function adjust(Request $request, int $user): array
    {
        $data = $request->validate([
            /** Positive adds, negative takes. Up to 2 decimals. */
            'amount' => ['required', 'numeric', 'not_in:0', 'between:-100000,100000', 'decimal:0,2'],
            'currency' => ['required', Rule::in(array_column(config('marketplace.currencies'), 'code'))],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $target = User::findOrFail($user);
        $cents = Money::toCents($data['amount']);

        $this->ledger->post('adjustment', [
            [$this->ledger->system(Account::GATEWAY, $data['currency']), -$cents],
            [$this->ledger->wallet($target, $data['currency']), $cents],
        ], ['description' => $data['reason'], 'by' => $request->user()]);

        return ['data' => $this->wallet->balances($target)];
    }
}
