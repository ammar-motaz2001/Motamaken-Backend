<?php

namespace App\Http\Controllers;

use App\Http\Requests\WalletHistoryRequest;
use App\Http\Requests\WalletStatsRequest;
use App\Http\Resources\LedgerEntryResource;
use App\Services\Payments\WalletService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Wallet & payments', 'The wallet: balances, history, deposits and withdrawals.', weight: 6)]
class WalletController extends Controller
{
    public function __construct(private readonly WalletService $wallet) {}

    /**
     * Wallet balances.
     *
     * One row per currency. `available` can be spent or withdrawn; `inEscrow` is what you paid into escrow and
     * not yet released; `incoming` is what is held for you (before the platform fee); `pendingWithdrawals` waits for an admin.
     *
     * @return array{data: list<array{currency: string, available: float, inEscrow: float, incoming: float, pendingWithdrawals: float}>}
     */
    public function show(Request $request): array
    {
        return ['data' => $this->wallet->balances($request->user())];
    }

    /**
     * Wallet history.
     *
     * Every movement of money in or out of the wallet, newest first.
     */
    public function transactions(WalletHistoryRequest $request): AnonymousResourceCollection
    {
        return LedgerEntryResource::collection(
            $this->wallet->history($request->user(), $request->input('currency'), $request->input('type'))
                ->paginate($request->integer('perPage', 20)),
        );
    }

    /**
     * Monthly chart.
     *
     * Money in and out per month, oldest first, for the bar chart on the wallet page.
     *
     * @return array{currency: string, data: list<array{month: string, income: float, spending: float}>}
     */
    public function stats(WalletStatsRequest $request): array
    {
        $currency = $request->input('currency', $request->user()->currency);

        return ['currency' => $currency, 'data' => $this->wallet->monthly($request->user(), $currency, $request->integer('months', 6))];
    }
}
