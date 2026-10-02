<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Requests\DepositRequest;
use App\Http\Requests\PaginatedRequest;
use App\Http\Resources\DepositResource;
use App\Models\Deposit;
use App\Services\Payments\DepositService;
use App\Support\Money;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Wallet & payments', 'The wallet: balances, history, deposits and withdrawals.', weight: 6)]
class DepositController extends Controller
{
    public function __construct(private readonly DepositService $deposits) {}

    /**
     * List deposits.
     */
    public function index(PaginatedRequest $request): AnonymousResourceCollection
    {
        return DepositResource::collection(
            Deposit::where('user_id', $request->user()->id)->latest()->latest('id')->paginate($request->integer('perPage', 15)),
        );
    }

    /**
     * Add money.
     *
     * Starts a payment and returns the `checkoutUrl` to send the customer to. The money reaches the wallet when the
     * payment provider confirms it (webhook): poll `GET /wallet/deposits` or watch the bell for `deposit.succeeded`.
     */
    public function store(DepositRequest $request): JsonResponse
    {
        $deposit = $this->deposits->start(
            $request->user(), Money::toCents($request->input('amount')), $request->input('currency'), $request->input('method'),
        );

        return (new DepositResource($deposit))->response()->setStatusCode(201);
    }

    /**
     * Simulate a successful payment.
     *
     * Development only, with the fake gateway: completes your own pending deposit as if the provider confirmed it.
     * Not available in production.
     */
    public function simulate(Request $request, int $deposit): DepositResource
    {
        abort_if(app()->isProduction() || config('payments.driver') !== 'fake', 404);

        $deposit = Deposit::where('user_id', $request->user()->id)->findOrFail($deposit);

        if ($deposit->status !== Deposit::PENDING) {
            throw new ApiException(__('payments.deposit_not_pending'), 'deposit_not_pending');
        }

        return new DepositResource($this->deposits->succeed($deposit));
    }
}
