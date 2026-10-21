<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Exceptions\ApiException;
use App\Models\Deposit;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Development gateway: hands out a made-up checkout page and never charges anyone. The payment is completed by calling
 * the webhook (or `POST /wallet/deposits/{id}/simulate` in local environments).
 */
class FakeGateway implements PaymentGateway
{
    /**
     * Point the customer to a made-up checkout page; the payment stays pending until the webhook is simulated.
     */
    public function createCheckout(Deposit $deposit, ?string $cardToken = null): array
    {
        return ['checkoutUrl' => rtrim(config('app.url'), '/')."/fake-checkout/{$deposit->provider_ref}", 'status' => 'pending', 'message' => null];
    }

    /**
     * Always pending: only the simulated webhook completes a fake payment.
     */
    public function paymentStatus(Deposit $deposit): array
    {
        return ['status' => 'pending', 'amountCents' => $deposit->amount_cents, 'currency' => $deposit->currency, 'message' => null];
    }

    /**
     * Return a made-up card token without storing or checking anything.
     */
    public function saveCard(User $user, array $card): string
    {
        return 'fake_pm_'.Str::lower(Str::random(24));
    }

    /**
     * Browser-made card tokens only exist at a real provider, so they are refused here.
     */
    public function cardFromToken(User $user, string $token): array
    {
        throw new ApiException(__('payments.card_token_unsupported'), 'card_token_unsupported');
    }
}
