<?php

namespace App\Http\Controllers;

use App\Models\Deposit;
use App\Services\Payments\DepositService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Wallet & payments', 'The wallet: balances, history, deposits and withdrawals.', weight: 6)]
class MoyasarWebhookController extends Controller
{
    /**
     * Moyasar webhook.
     *
     * Moyasar calls it when a payment changes (`payment_paid`, `payment_failed`, ...). The body carries the webhook's
     * `secret_token`; the payment itself is then re-read from Moyasar with the secret key, so a forged body cannot credit
     * a wallet. Backs up the customer's return from 3-D Secure (closed tab, lost connection).
     *
     * @unauthenticated
     */
    public function __invoke(Request $request, DepositService $deposits): array
    {
        $secret = (string) config('payments.moyasar.webhook_secret');
        abort_unless($secret !== '' && hash_equals($secret, (string) $request->input('secret_token')), 403, __('payments.bad_signature'));

        $paymentId = (string) $request->input('data.id');
        $deposit = $paymentId !== '' ? Deposit::where('provider', 'moyasar')->where('provider_ref', $paymentId)->first() : null;

        // Payments made elsewhere on the same Moyasar account are not ours: acknowledge so Moyasar stops retrying.
        if ($deposit) {
            $deposits->refresh($deposit);
        }

        return ['received' => true];
    }
}
