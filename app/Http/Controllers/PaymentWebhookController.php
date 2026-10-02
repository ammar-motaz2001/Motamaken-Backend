<?php

namespace App\Http\Controllers;

use App\Models\Deposit;
use App\Services\Payments\DepositService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Wallet & payments', 'The wallet: balances, history, deposits and withdrawals.', weight: 6)]
class PaymentWebhookController extends Controller
{
    /**
     * Payment webhook.
     *
     * Called by the payment provider, not by the frontend. The body must be signed: `X-Signature` is the HMAC-SHA256
     * of the raw body with the shared secret. Safe to repeat: a payment is only ever credited once.
     *
     * @unauthenticated
     *
     * @return array{received: bool}
     */
    public function __invoke(Request $request, DepositService $deposits): array
    {
        $expected = hash_hmac('sha256', $request->getContent(), config('payments.webhook_secret'));

        abort_unless(hash_equals($expected, (string) $request->header('X-Signature')), 403, __('payments.bad_signature'));

        $data = $request->validate([
            /** The provider's reference of the payment, from `checkoutUrl`. */
            'ref' => ['required', 'string'],
            'status' => ['required', 'in:succeeded,failed'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $deposit = Deposit::where('provider_ref', $data['ref'])->firstOrFail();

        $data['status'] === 'succeeded'
            ? $deposits->succeed($deposit)
            : $deposits->fail($deposit, $data['reason'] ?? 'declined');

        return ['received' => true];
    }
}
