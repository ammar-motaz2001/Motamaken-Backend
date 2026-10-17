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
}
