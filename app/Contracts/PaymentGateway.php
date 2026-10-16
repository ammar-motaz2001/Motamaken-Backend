<?php

namespace App\Contracts;

use App\Models\Deposit;
use App\Models\User;

interface PaymentGateway
{
    /**
     * Start a payment at the provider for a saved deposit. `$deposit->provider_ref` is already set (a UUID the provider
     * may adopt as its own payment id, which makes retries safe).
     *
     * @param  string|null  $cardToken  A card token made in the browser (never a card number).
     * @return array{checkoutUrl: ?string, status: 'pending'|'paid'|'failed', message: ?string} Where to send the customer
     *                                                                                          (3-D Secure), or the outcome when the provider decided at once.
     */
    public function createCheckout(Deposit $deposit, ?string $cardToken = null): array;

    /**
     * Ask the provider how a payment ended. Never trust the browser for this.
     *
     * @return array{status: 'pending'|'paid'|'failed', amountCents: int, currency: string, message: ?string}
     */
    public function paymentStatus(Deposit $deposit): array;

    /**
     * Keep a card typed into our own form (development gateways only).
     *
     * @param  array{number: string, expMonth: int, expYear: int, cvc: string, holderName: string}  $card
     * @return string The provider's token for the card.
     */
    public function saveCard(User $user, array $card): string;

    /**
     * Keep a card the browser already tokenised with the provider; the details come from the provider, not the browser.
     *
     * @return array{token: string, brand: string, last4: string, expMonth: int, expYear: int, holderName: string}
     */
    public function cardFromToken(User $user, string $token): array;
}
