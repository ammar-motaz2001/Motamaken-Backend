<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentMethodsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_a_card_is_saved_without_its_number_and_the_first_one_is_preferred(): void
    {
        $this->as($this->user)->postJson('/api/me/payment-methods', $this->card())
            ->assertCreated()
            ->assertJsonPath('data.type', 'card')
            ->assertJsonPath('data.brand', 'visa')
            ->assertJsonPath('data.last4', '1111')
            ->assertJsonPath('data.isDefault', true)
            ->assertJsonMissingPath('data.number');

        $row = PaymentMethod::sole();
        $this->assertStringNotContainsString('4111111111111111', json_encode($row->getAttributes()));
        $this->assertStringStartsWith('fake_pm_', $row->gateway_token);
    }

    public function test_card_details_are_validated(): void
    {
        $this->as($this->user)->postJson('/api/me/payment-methods', $this->card(['number' => '4111 1111 1111 1112', 'cvc' => '1', 'holderName' => '']))
            ->assertJsonValidationErrors(['number', 'cvc', 'holderName']);

        $this->as($this->user)->postJson('/api/me/payment-methods', $this->card(['expMonth' => 1, 'expYear' => now()->year - 1]))
            ->assertJsonValidationErrors('expYear');

        $this->assertSame(0, PaymentMethod::count());
    }

    public function test_the_preferred_method_can_change_and_moves_on_when_it_is_removed(): void
    {
        $card = $this->as($this->user)->postJson('/api/me/payment-methods', $this->card())->json('data.id');
        $paypal = $this->as($this->user)->postJson('/api/me/payment-methods', ['type' => 'paypal', 'email' => 'Me@Example.com'])
            ->assertCreated()->assertJsonPath('data.isDefault', false)->assertJsonPath('data.email', 'me•••@example.com')->json('data.id');

        $this->as($this->user)->postJson("/api/me/payment-methods/{$paypal}/default")->assertOk()->assertJsonPath('data.isDefault', true);
        $this->as($this->user)->getJson('/api/me/payment-methods')->assertJsonPath('data.0.id', $paypal)->assertJsonPath('data.1.isDefault', false);

        $this->as($this->user)->deleteJson("/api/me/payment-methods/{$paypal}")->assertOk();
        $this->as($this->user)->getJson('/api/me/payment-methods')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $card)
            ->assertJsonPath('data.0.isDefault', true);
    }

    public function test_other_peoples_methods_are_out_of_reach(): void
    {
        $id = $this->as($this->user)->postJson('/api/me/payment-methods', $this->card())->json('data.id');
        $other = User::factory()->create();

        $this->as($other)->getJson('/api/me/payment-methods')->assertJsonCount(0, 'data');
        $this->as($other)->deleteJson("/api/me/payment-methods/{$id}")->assertNotFound();
        $this->as($other)->postJson("/api/me/payment-methods/{$id}/default")->assertNotFound();
    }

    public function test_quick_feedback_is_recorded(): void
    {
        $this->as($this->user)->postJson('/api/feedback', ['context' => 'deposit', 'referenceId' => 7, 'rating' => 'up', 'comment' => 'Smooth'])
            ->assertCreated();
        $this->as($this->user)->postJson('/api/feedback', ['context' => 'nope', 'rating' => 'meh'])->assertJsonValidationErrors(['context', 'rating']);

        $this->assertDatabaseHas('feedback', ['user_id' => $this->user->id, 'context' => 'deposit', 'reference_id' => 7, 'rating' => 'up']);
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function card(array $override = []): array
    {
        return ['type' => 'card', 'holderName' => 'Ammar Motaz', 'number' => '4111 1111 1111 1111', 'expMonth' => 12, 'expYear' => now()->year + 2, 'cvc' => '123', ...$override];
    }

    private function as(User $user): static
    {
        return $this->actingAs($user, 'sanctum');
    }
}
