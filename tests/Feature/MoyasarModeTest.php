<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\MoyasarMode;
use App\Support\RuntimeConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoyasarModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payments.driver' => 'moyasar',
            'payments.moyasar.secret_key' => 'sk_test_one', 'payments.moyasar.publishable_key' => 'pk_test_one',
            'payments.moyasar.legacy' => ['secret_key' => 'sk_test_one', 'publishable_key' => 'pk_test_one', 'webhook_secret' => null],
            'payments.moyasar.keys.live' => ['secret_key' => 'sk_live_two', 'publishable_key' => 'pk_live_two', 'webhook_secret' => 'live-hook'],
            'payments.moyasar.keys.test' => ['secret_key' => null, 'publishable_key' => null, 'webhook_secret' => null],
        ]);
    }

    public function test_admins_switch_between_test_and_live_and_the_website_gets_the_matching_public_key(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->assertSame(['test', 'live'], MoyasarMode::available());
        $this->getJson('/api/site-settings')->assertJsonPath('data.payments', ['moyasarPublishableKey' => 'pk_test_one', 'mode' => 'test']);

        $this->actingAs($admin, 'sanctum')->putJson('/api/admin/integrations/payments/mode', ['mode' => 'live'])
            ->assertOk()->assertJsonPath('data.0.details.mode', 'live');
        $this->assertSame('sk_live_two', config('payments.moyasar.secret_key'));
        $this->getJson('/api/site-settings')->assertJsonPath('data.payments.moyasarPublishableKey', 'pk_live_two');

        // Back to test: the single legacy key pair is used again.
        $this->actingAs($admin, 'sanctum')->putJson('/api/admin/integrations/payments/mode', ['mode' => 'test'])->assertOk();
        RuntimeConfig::apply();
        $this->assertSame('sk_test_one', config('payments.moyasar.secret_key'));
    }

    public function test_a_mode_without_keys_on_the_server_is_refused(): void
    {
        config(['payments.moyasar.keys.live.secret_key' => null]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin, 'sanctum')->putJson('/api/admin/integrations/payments/mode', ['mode' => 'live'])->assertStatus(422);
        $this->assertSame('sk_test_one', config('payments.moyasar.secret_key'));
    }

    public function test_webhooks_from_either_mode_are_accepted_and_others_refused(): void
    {
        config(['payments.moyasar.webhook_secret' => 'test-hook']);

        $this->postJson('/api/webhooks/moyasar', ['secret_token' => 'live-hook', 'data' => ['id' => 'nope']])->assertOk();
        $this->postJson('/api/webhooks/moyasar', ['secret_token' => 'test-hook', 'data' => ['id' => 'nope']])->assertOk();
        $this->postJson('/api/webhooks/moyasar', ['secret_token' => 'forged', 'data' => ['id' => 'nope']])->assertForbidden();
    }
}
