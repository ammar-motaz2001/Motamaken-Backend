<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PayoutRequest;
use App\Http\Requests\Admin\RejectRequest;
use App\Http\Resources\WithdrawalResource;
use App\Models\Withdrawal;
use App\Services\Payments\WithdrawalService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminWithdrawalController extends Controller
{
    public function __construct(private readonly WithdrawalService $withdrawals) {}

    /**
     * List withdrawals.
     *
     * Everyone's withdrawal requests, oldest pending first by default, for the finance page.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::in([Withdrawal::PENDING, Withdrawal::PAID, Withdrawal::REJECTED, Withdrawal::CANCELLED])],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return WithdrawalResource::collection(
            Withdrawal::with('user')
                ->when($request->input('status'), fn ($query, $status) => $query->where('status', $status))
                ->orderBy('id', $request->input('status', 'pending') === 'pending' ? 'asc' : 'desc')
                ->paginate($request->integer('perPage', 20)),
        );
    }

    /**
     * Mark a withdrawal as paid.
     *
     * After sending the money by bank transfer / PayPal. The held amount leaves the platform and the fee becomes revenue.
     */
    public function approve(PayoutRequest $request, int $withdrawal): WithdrawalResource
    {
        $withdrawal = Withdrawal::findOrFail($withdrawal);

        return new WithdrawalResource($this->withdrawals->markPaid($withdrawal, $request->user(), $request->input('reference'))->load('user'));
    }

    /**
     * Reject a withdrawal.
     *
     * The held amount and fee go back to the user's wallet and they are told why.
     */
    public function reject(RejectRequest $request, int $withdrawal): WithdrawalResource
    {
        $withdrawal = Withdrawal::findOrFail($withdrawal);

        return new WithdrawalResource($this->withdrawals->reject($withdrawal, $request->user(), $request->input('reason'))->load('user'));
    }
}
