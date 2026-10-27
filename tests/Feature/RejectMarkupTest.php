<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RejectMarkupTest extends TestCase
{
    use RefreshDatabase;

    public function test_html_and_script_markup_is_refused_in_user_text(): void
    {
        $user = User::factory()->create();

        foreach (['<img src=x onerror=alert(0)>', '<script>alert(0)</script>', 'hi <b>there</b>', 'javascript:alert(1)', '" onmouseover="alert(1)'] as $evil) {
            $this->actingAs($user, 'sanctum')->patchJson('/api/me', ['firstName' => 'Sara', 'username' => 'sara.ok', 'lastName' => $evil])
                ->assertStatus(422)->assertJsonValidationErrors('lastName');
        }

        // Normal text with symbols still goes through.
        $this->actingAs($user, 'sanctum')->patchJson('/api/me', ['firstName' => 'Sara', 'lastName' => 'Ali', 'username' => 'sara_2026'])->assertOk();
    }

    public function test_passwords_may_contain_angle_brackets(): void
    {
        $this->postJson('/api/auth/login', ['method' => 'email', 'identifier' => 'x@example.com', 'password' => '<Secret#1>'])
            ->assertStatus(422)->assertJsonMissingValidationErrors('password');
    }
}
