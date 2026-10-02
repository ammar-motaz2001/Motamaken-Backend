<?php

namespace App\Contracts;

use App\Models\Deposit;

interface PaymentGateway
{
    /**
     * Start a payment at the provider.
     *
     * @return array{ref: string, checkoutUrl: string} The provider's reference and the page to send the customer to.
     */
    public function createCheckout(Deposit $deposit): array;
}
