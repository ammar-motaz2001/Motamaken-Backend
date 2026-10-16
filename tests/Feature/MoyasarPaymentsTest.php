<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Models\Deposit;
use App\Models\User;
use App\Services\Payments\MoyasarGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\HandlesMoney;
use Tests\TestCase;

class MoyasarPaymentsTest extends TestCase
{
    use HandlesMoney, RefreshDatabase;

    private const API = 'https://api.moyasar.com/v1';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['payments.driver' => 'moyasar']);
        $this->app->bind(PaymentGateway::class, MoyasarGateway::class);
        $this->user = User::factory()->create();
    }

    public function test_a_top_up_goes_through_3ds_and_is_credited_only_after_moyasar_confirms(): void
    {
        $payments = [];
        Http::fake([
            self::API.'/payments' => function (Request $request) use (&$payments) {
                $payments[$request['given_id']] = $request->data();

                return Http::response($this->payment($request['given_id'], 'initiated', $request['amount'], $request['currency'], 'https://api.moyasar.com/v1/card_auth/x/prepare'), 201);
            },
            self::API.'/payments/*' => function (Request $request) use (&$payments) {
                $id = basename($request->url());

                return Http::response($this->payment($id, 'paid', $payments[$id]['amount'], $payments[$id]['currency']));
            },
        ]);

        $deposit = $this->as()->postJson('/api/wallet/deposits', ['amount' => 25.5, 'currency' => 'SAR', 'method' => 'card', 'cardToken' => 'token_abc123'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.checkoutUrl', 'https://api.moyasar.com/v1/card_auth/x/prepare')->json('data');

        $sent = array_values($payments)[0];
        $this->assertSame(2550, $sent['amount']);
        $this->assertSame(['type' => 'token', 'token' => 'token_abc123'], $sent['source']);
        $this->assertStringEndsWith("/payments/return?deposit={$deposit['id']}", $sent['callback_url']);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Basic '.base64_encode('sk_test_unit:')));
        $this->assertSame(0.0, $this->balance($this->user, 'SAR'), 'nothing is credited before Moyasar confirms');

        $this->as()->postJson("/api/wallet/deposits/{$deposit['id']}/refresh")->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->as()->postJson("/api/wallet/deposits/{$deposit['id']}/refresh")->assertOk();
        $this->assertSame(25.5, $this->balance($this->user, 'SAR'), 'credited exactly once');
        $this->assertBooksBalance();
    }

    public function test_a_different_amount_at_moyasar_is_never_credited(): void
    {
        Http::fake([
            self::API.'/payments' => fn (Request $request) => Http::response($this->payment($request['given_id'], 'initiated', $request['amount'], 'SAR', 'https://3ds'), 201),
            self::API.'/payments/*' => fn (Request $request) => Http::response($this->payment(basename($request->url()), 'paid', 100, 'SAR')),
        ]);

        $id = $this->as()->postJson('/api/wallet/deposits', ['amount' => 500, 'currency' => 'SAR', 'method' => 'card', 'cardToken' => 'token_abc'])->json('data.id');
        $this->as()->postJson("/api/wallet/deposits/{$id}/refresh")->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.failureReason', 'The amount charged does not match this top-up.');
        $this->assertSame(0.0, $this->balance($this->user, 'SAR'));
    }

    public function test_declined_cards_and_missing_cards(): void
    {
        Http::fake([self::API.'/payments' => Http::response(['type' => 'invalid_request_error', 'message' => 'Card declined', 'errors' => ['source' => ['Insufficient funds']]], 400)]);

        $this->as()->postJson('/api/wallet/deposits', ['amount' => 10, 'currency' => 'SAR', 'method' => 'card'])->assertJsonPath('code', 'card_required');
        // Paying for a 3.99 promotion by card is fine; a 3.99 plain top-up is under the minimum; nothing goes under 1.
        $this->as()->postJson('/api/wallet/deposits', ['amount' => 3.99, 'currency' => 'SAR', 'method' => 'card', 'cardToken' => 'token_x'])->assertJsonPath('code', 'amount_too_small');
        $this->as()->postJson('/api/wallet/deposits', ['amount' => 0.5, 'currency' => 'SAR', 'method' => 'card', 'cardToken' => 'token_x', 'purpose' => 'purchase'])->assertJsonPath('code', 'amount_too_small');
        $this->as()->postJson('/api/wallet/deposits', ['amount' => 3.99, 'currency' => 'SAR', 'method' => 'card', 'cardToken' => 'token_x', 'purpose' => 'purchase'])->assertJsonPath('code', 'payment_failed');
        $this->as()->postJson('/api/wallet/deposits', ['amount' => 10, 'currency' => 'SAR', 'method' => 'card', 'cardToken' => '4111111111111111'])->assertJsonValidationErrors('cardToken');
        $this->as()->postJson('/api/wallet/deposits', ['amount' => 10, 'currency' => 'SAR', 'method' => 'card', 'cardToken' => 'token_x'])
            ->assertJsonPath('code', 'payment_failed')->assertJsonPath('message', 'Card declined (Insufficient funds)');
        $this->assertSame(['failed', 'failed', 'failed'], Deposit::orderBy('id')->pluck('status')->all(), 'declined attempts are kept as failed; refused amounts never reach Moyasar');
    }

    public function test_cards_are_saved_from_moyasar_tokens_and_card_numbers_are_refused(): void
    {
        Http::fake([
            self::API.'/tokens/token_unverified' => Http::response(['id' => 'token_unverified', 'status' => 'save_only', 'brand' => 'visa', 'last_four' => '1111', 'month' => '09', 'year' => '2031', 'name' => 'Nora Ali']),
            self::API.'/tokens/token_good' => Http::response(['id' => 'token_good', 'status' => 'active', 'brand' => 'mada', 'last_four' => '4242', 'month' => '09', 'year' => '2031', 'name' => 'Nora Ali']),
            self::API.'/tokens/*' => Http::response(['type' => 'record_not_found'], 404),
            self::API.'/payments' => fn (Request $request) => Http::response($this->payment($request['given_id'], 'paid', $request['amount'], 'SAR'), 201),
            self::API.'/payments/*' => fn (Request $request) => Http::response($this->payment(basename($request->url()), 'paid', 1000, 'SAR')),
        ]);

        $this->as()->postJson('/api/me/payment-methods', ['type' => 'card', 'holderName' => 'Nora', 'number' => '4111111111111111', 'expMonth' => 12, 'expYear' => 2031, 'cvc' => '123'])
            ->assertJsonPath('code', 'card_token_required');
        $this->as()->postJson('/api/me/payment-methods', ['type' => 'card', 'token' => 'token_bad'])->assertJsonPath('code', 'card_invalid');
        $this->as()->postJson('/api/me/payment-methods', ['type' => 'card', 'token' => 'token_unverified'])->assertJsonPath('code', 'card_not_verified');
        $card = $this->as()->postJson('/api/me/payment-methods', ['type' => 'card', 'token' => 'token_good'])->assertCreated()
            ->assertJsonPath('data.brand', 'mada')->assertJsonPath('data.last4', '4242')->json('data.id');

        // A card that needs no 3-D Secure is paid on the spot.
        $this->as()->postJson('/api/wallet/deposits', ['amount' => 10, 'currency' => 'SAR', 'method' => 'card', 'paymentMethodId' => $card])
            ->assertCreated()->assertJsonPath('data.status', 'succeeded');
        Http::assertSent(fn (Request $request) => $request->url() === self::API.'/payments' && $request['source']['token'] === 'token_good');
        $this->assertSame(10.0, $this->balance($this->user, 'SAR'));

        $other = User::factory()->create();
        $this->actingAs($other, 'sanctum')->postJson('/api/wallet/deposits', ['amount' => 10, 'currency' => 'SAR', 'method' => 'card', 'paymentMethodId' => $card])
            ->assertJsonValidationErrors('paymentMethodId');
    }

    public function test_the_webhook_needs_the_secret_and_re_reads_the_payment(): void
    {
        Http::fake([
            self::API.'/payments' => fn (Request $request) => Http::response($this->payment($request['given_id'], 'initiated', $request['amount'], 'SAR', 'https://3ds'), 201),
            self::API.'/payments/*' => fn (Request $request) => Http::response($this->payment(basename($request->url()), 'paid', 1500, 'SAR')),
        ]);
        $id = $this->as()->postJson('/api/wallet/deposits', ['amount' => 15, 'currency' => 'SAR', 'method' => 'card', 'cardToken' => 'token_abc'])->json('data.id');
        $ref = Deposit::find($id)->provider_ref;

        $this->postJson('/api/webhooks/moyasar', ['type' => 'payment_paid', 'secret_token' => 'wrong', 'data' => ['id' => $ref]])->assertForbidden();
        $this->postJson('/api/webhooks/moyasar', ['type' => 'payment_paid', 'secret_token' => 'whsec_unit', 'data' => ['id' => 'someone-elses']])->assertOk();
        $this->postJson('/api/webhooks/moyasar', ['type' => 'payment_paid', 'secret_token' => 'whsec_unit', 'data' => ['id' => $ref, 'amount' => 999999]])->assertOk();

        $this->assertSame('succeeded', Deposit::find($id)->status);
        $this->assertSame(15.0, $this->balance($this->user, 'SAR'), 'the amount comes from Moyasar, not the webhook body');
    }

    private function as(): static
    {
        return $this->actingAs($this->user, 'sanctum');
    }

    /**
     * @return array<string, mixed>
     */
    private function payment(string $id, string $status, int $amount, string $currency, ?string $transactionUrl = null): array
    {
        return [
            'id' => $id, 'status' => $status, 'amount' => $amount, 'currency' => $currency,
            'source' => ['type' => 'creditcard', 'company' => 'visa', 'message' => $status === 'paid' ? 'APPROVED' : null, 'transaction_url' => $transactionUrl],
        ];
    }
}
