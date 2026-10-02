<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaginatedRequest;
use App\Http\Requests\WithdrawalRequest;
use App\Http\Resources\WithdrawalResource;
use App\Models\Withdrawal;
use App\Services\Payments\WithdrawalService;
use App\Support\Money;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Wallet & payments', 'The wallet: balances, history, deposits and withdrawals.', weight: 6)]
class WithdrawalController extends Controller
{
    public function __construct(private readonly WithdrawalService $withdrawals) {}

    /**
     * List withdrawals.
     */
    public function index(PaginatedRequest $request): AnonymousResourceCollection
    {
        return WithdrawalResource::collection(
            Withdrawal::where('user_id', $request->user()->id)->latest()->latest('id')->paginate($request->integer('perPage', 15)),
        );
    }

    /**
     * Withdraw money.
     *
     * The amount plus the withdrawal fee leaves the wallet right away and waits for an admin to pay it out. Needs a
     * verified identity (`kyc_required` otherwise).
     *
     * Refusals answer `{message, code}`: `kyc_required`, `amount_too_small`, `insufficient_funds`.
     */
    public function store(WithdrawalRequest $request): JsonResponse
    {
        $withdrawal = $this->withdrawals->request(
            $request->user(), Money::toCents($request->input('amount')), $request->input('currency'),
            $request->input('method'), $request->input('destination'),
        );

        return (new WithdrawalResource($withdrawal))->response()->setStatusCode(201);
    }

    /**
     * Cancel a withdrawal.
     *
     * While it is still pending: the money returns to the wallet.
     */
    public function cancel(Request $request, int $withdrawal): WithdrawalResource
    {
        $withdrawal = Withdrawal::where('user_id', $request->user()->id)->findOrFail($withdrawal);

        return new WithdrawalResource($this->withdrawals->cancel($withdrawal));
    }
}
