<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Deposit;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Development gateway: hands out a made-up checkout page and never charges anyone. The payment is completed by calling
 * the webhook (or `POST /wallet/deposits/{id}/simulate` in local environments).
 */
class FakeGateway implements PaymentGateway
{
    public function createCheckout(Deposit $deposit): array
    {
        $ref = 'fake_'.Str::lower(Str::random(20));

        return ['ref' => $ref, 'checkoutUrl' => rtrim(config('app.url'), '/')."/fake-checkout/{$ref}"];
    }

    public function saveCard(User $user, array $card): string
    {
        return 'fake_pm_'.Str::lower(Str::random(24));
    }
}
