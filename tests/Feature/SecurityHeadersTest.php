<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_api_responses_carry_security_headers_and_the_health_check_reveals_no_versions(): void
    {
        $response = $this->getJson('/api/health')->assertOk()->assertExactJson(['status' => 'ok', 'database' => 'ok', 'redis' => 'ok']);

        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; sandbox")
            ->assertHeaderMissing('X-Powered-By');

        $this->getJson('/api/nope')->assertNotFound()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_the_api_root_is_a_plain_pointer_with_headers_and_no_cookies(): void
    {
        $response = $this->get('/')->assertOk()->assertJsonPath('health', url('/api/health'));

        $response->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; sandbox")
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $this->assertEmpty($response->headers->getCookies());
    }

    public function test_a_guest_gets_a_json_401_even_without_asking_for_json(): void
    {
        $this->get('/api/me/summary')->assertUnauthorized()->assertJsonStructure(['message']);
    }
}
