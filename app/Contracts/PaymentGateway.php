<?php

namespace App\Contracts;

use App\Models\Deposit;
use App\Models\User;

interface PaymentGateway
{
    /**
     * Start a payment at the provider.
     *
     * @return array{ref: string, checkoutUrl: string} The provider's reference and the page to send the customer to.
     */
    public function createCheckout(Deposit $deposit): array;

    /**
     * Keep a card at the provider for later payments.
     *
     * @param  array{number: string, expMonth: int, expYear: int, cvc: string, holderName: string}  $card
     * @return string The provider's token for the card.
     */
    public function saveCard(User $user, array $card): string;
}
