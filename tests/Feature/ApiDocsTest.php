<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\City;
use App\Models\Country;
use App\Models\Project;
use App\Models\User;
use App\OpenApi\ApiExamples;
use App\Services\Chat\ConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ApiDocsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Docs are local-only by default.
        Gate::define('viewApiDocs', fn (?User $user = null) => true);
    }

    public function test_spec_documents_every_example_operation(): void
    {
        $spec = $this->getJson('/docs/api.json')->assertOk()->json();

        foreach (array_keys(ApiExamples::operations()) as $key) {
            [$method, $path] = explode(' ', $key, 2);

            $this->assertArrayHasKey(strtolower($method), $spec['paths'][$path] ?? [], "{$key} is not in the spec");
        }

        $this->assertSame(
            ['with-email', 'with-phone'],
            array_keys($spec['paths']['/auth/login']['post']['requestBody']['content']['application/json']['examples']),
        );
    }

    public function test_request_examples_pass_validation(): void
    {
        Notification::fake();

        // Records the examples refer to: users 1..12 (the caller is 1), projects 1..3 and a conversation with user 8.
        $users = User::factory()->count(12)->create();
        Project::factory()->count(3)->create();
        // Categories 4-6 are project categories and 7 is a platform ("Instagram"); country 1 has city 1, country 2 has cities 2-4.
        Category::factory()->count(3)->create();
        Category::factory()->influencer()->create();
        City::factory()->create(['country_id' => Country::factory()->create()->id]);
        City::factory()->count(3)->create(['country_id' => Country::factory()->create()->id]);
        $conversation = app(ConversationService::class)->findOrCreate($users[0], $users[7])[0];
        $this->actingAs($users[0], 'sanctum');

        $checked = 0;

        foreach (ApiExamples::operations() as $key => $examples) {
            [$method, $path] = explode(' ', $key, 2);
            $url = '/api'.preg_replace(['/\{conversation\}/', '/\{\w+\}/'], [(string) $conversation->id, '1'], $path);

            foreach ([...$examples['request'] ?? [], ...$examples['requestJson'] ?? []] as $name => $body) {
                $response = $this->json($method, $url, $body);

                // Passing validation is the point: sample codes and passwords are rightly refused by the business rules.
                $this->assertNull($response->json('errors'), "{$key} example \"{$name}\" fails validation: ".$response->getContent());
                // 403/404 are fine here: the fixtures do not make the caller the owner of project 1.
                $this->assertNotContains($response->status(), [401, 405, 500], "{$key} example \"{$name}\" never reached the endpoint");
                $checked++;
            }
        }

        $this->assertGreaterThan(10, $checked);
    }

    public function test_swagger_ui_is_served(): void
    {
        $this->get('/swagger')->assertOk()->assertSee('swagger-ui', false)->assertSee(route('docs.spec'), false);
    }
}
