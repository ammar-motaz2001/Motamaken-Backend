<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Exceptions\ApiException;
use App\Models\Deposit;
use App\Models\IntegrationLog;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Moyasar (https://moyasar.com). Card numbers never reach this server: the browser turns the card into a token with the
 * publishable key, and we charge that token with the secret key. 3-D Secure happens on Moyasar's page
 * (`source.transaction_url`), which sends the customer back to FRONTEND_URL{return_path}?deposit={id}&id=...&status=...
 */
class MoyasarGateway implements PaymentGateway
{
    private const PAID = ['paid', 'captured'];

    private const FAILED = ['failed', 'expired', 'voided', 'refunded'];

    public function createCheckout(Deposit $deposit, ?string $cardToken = null): array
    {
        if (! $cardToken) {
            throw new ApiException(__('payments.card_required'), 'card_required');
        }

        $response = $this->send(fn (PendingRequest $http) => $http->post('/payments', [
            // Moyasar adopts it as the payment id: sending the same deposit twice can never charge twice.
            'given_id' => $deposit->provider_ref,
            'amount' => $deposit->amount_cents,
            'currency' => $deposit->currency,
            'description' => config('app.name')." wallet top-up #{$deposit->id}",
            'callback_url' => config('auth_flow.frontend_url').config('payments.moyasar.return_path')."?deposit={$deposit->id}",
            'source' => ['type' => 'token', 'token' => $cardToken],
            'metadata' => ['deposit_id' => (string) $deposit->id, 'user_id' => (string) $deposit->user_id],
        ]));

        if (! $response->successful()) {
            IntegrationLog::record('payments', "Moyasar refused a payment ({$response->status()})", ['depositId' => $deposit->id, 'type' => $response->json('type')], 'warning');

            throw new ApiException($this->errorMessage($response), 'payment_failed');
        }

        $payment = $response->json();

        return [
            'checkoutUrl' => $payment['source']['transaction_url'] ?? null,
            'status' => $this->status($payment['status'] ?? null),
            'message' => $payment['source']['message'] ?? null,
        ];
    }

    public function paymentStatus(Deposit $deposit): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('/payments/'.rawurlencode($deposit->provider_ref)));

        // Not found: the customer never got past the card form, so nothing was charged yet.
        if ($response->status() === 404) {
            return ['status' => 'pending', 'amountCents' => $deposit->amount_cents, 'currency' => $deposit->currency, 'message' => null];
        }
        if (! $response->successful()) {
            throw new ApiException(__('payments.provider_unavailable'), 'provider_unavailable', 503);
        }

        $payment = $response->json();

        return [
            'status' => $this->status($payment['status'] ?? null),
            'amountCents' => (int) ($payment['amount'] ?? 0),
            'currency' => (string) ($payment['currency'] ?? ''),
            'message' => $payment['source']['message'] ?? null,
        ];
    }

    public function saveCard(User $user, array $card): string
    {
        // Typed card numbers are refused: the website tokenises the card with Moyasar first.
        throw new ApiException(__('payments.card_token_required'), 'card_token_required');
    }

    public function cardFromToken(User $user, string $token): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('/tokens/'.rawurlencode($token)));
        $card = $response->json();

        if (! $response->successful() || ($card['status'] ?? null) === 'failed') {
            throw new ApiException(__('payments.card_invalid'), 'card_invalid');
        }
        // Only a card the customer confirmed with their bank stays usable; one-payment tokens expire in minutes.
        if (($card['status'] ?? null) !== 'active') {
            throw new ApiException(__('payments.card_not_verified'), 'card_not_verified');
        }

        return [
            'token' => $card['id'],
            'brand' => (string) ($card['brand'] ?? 'other'),
            'last4' => (string) ($card['last_four'] ?? ''),
            'expMonth' => (int) ($card['month'] ?? 0),
            'expYear' => (int) ($card['year'] ?? 0),
            'holderName' => (string) ($card['name'] ?? ''),
        ];
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call): Response
    {
        try {
            return $call(Http::baseUrl(config('payments.moyasar.api_url'))
                ->withBasicAuth((string) config('payments.moyasar.secret_key'), '')
                ->acceptJson()->asJson()->timeout(20));
        } catch (Throwable $e) {
            Log::warning("Moyasar request failed: {$e->getMessage()}");
            IntegrationLog::record('payments', "Moyasar unreachable: {$e->getMessage()}");

            throw new ApiException(__('payments.provider_unavailable'), 'provider_unavailable', 503);
        }
    }

    /** @return 'pending'|'paid'|'failed' */
    private function status(?string $moyasar): string
    {
        return match (true) {
            in_array($moyasar, self::PAID, true) => 'paid',
            in_array($moyasar, self::FAILED, true) => 'failed',
            default => 'pending',
        };
    }

    private function errorMessage(Response $response): string
    {
        $body = $response->json() ?? [];
        $first = collect($body['errors'] ?? [])->flatten()->first();

        return trim(($body['message'] ?? __('payments.payment_failed')).($first ? " ({$first})" : ''));
    }
}
