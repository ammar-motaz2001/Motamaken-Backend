<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Feedback;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminModerationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    public function test_the_session_user_says_whether_they_are_an_admin(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/auth/me')->assertJsonPath('data.isAdmin', true);
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/auth/me')->assertJsonPath('data.isAdmin', false);
    }

    public function test_admins_review_reports_and_the_reporter_sees_the_decision(): void
    {
        $reporter = User::factory()->create();
        $owner = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id, 'title' => 'Fake giveaway']);

        $this->actingAs($reporter, 'sanctum')->postJson('/api/reports', ['targetType' => 'project', 'targetId' => $project->id, 'reason' => 'scam'])->assertCreated();
        $this->actingAs($reporter, 'sanctum')->postJson('/api/reports', ['targetType' => 'user', 'targetId' => $owner->id, 'reason' => 'fake'])->assertCreated();
        $this->actingAs($reporter, 'sanctum')->getJson('/api/admin/reports')->assertNotFound();

        $this->app['auth']->forgetGuards();
        $list = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/reports?targetType=project')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.target', ['title' => 'Fake giveaway', 'path' => "/projects/{$project->id}"])
            ->assertJsonPath('data.0.reporter.id', $reporter->id)->assertJsonPath('counts', ['open' => 2, 'actioned' => 0, 'dismissed' => 0]);
        $id = $list->json('data.0.id');

        $this->postJson("/api/admin/reports/{$id}/resolve", ['decision' => 'open'])->assertJsonValidationErrors('decision');
        $this->postJson("/api/admin/reports/{$id}/resolve", ['decision' => 'actioned', 'note' => 'Project closed'])->assertOk()->assertJsonPath('data.status', 'actioned');
        $this->postJson("/api/admin/reports/{$id}/resolve", ['decision' => 'dismissed'])->assertJsonPath('code', 'report_closed');
        $this->getJson('/api/admin/reports?status=actioned')->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/overview')->assertJsonPath('queues.openReports', 1)->assertJsonPath('queues.newContactMessages', 0);

        $this->app['auth']->forgetGuards();
        $this->actingAs($reporter, 'sanctum')->getJson('/api/me/reports')->assertJsonPath('data.1.status', 'actioned')->assertJsonPath('data.1.resolutionNote', 'Project closed');
    }

    public function test_admins_read_payment_feedback_with_satisfaction_rates(): void
    {
        $user = User::factory()->create();
        foreach ([['deposit', 'up'], ['deposit', 'up'], ['deposit', 'down'], ['promotion', 'down']] as [$context, $rating]) {
            Feedback::create(['user_id' => $user->id, 'context' => $context, 'rating' => $rating, 'comment' => $rating === 'down' ? 'Slow' : null]);
        }

        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/feedback?rating=down')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.user.id', $user->id);
        $summary = collect($response->json('summary'))->keyBy('context');
        $this->assertSame(['context' => 'deposit', 'up' => 2, 'down' => 1, 'satisfaction' => 67], $summary['deposit']);
        $this->assertNull($summary['escrow']['satisfaction']);
    }

    public function test_admins_manage_categories_but_cannot_delete_used_ones(): void
    {
        $used = Category::factory()->create(['type' => 'project', 'slug' => 'design']);
        Project::factory()->create(['category_id' => $used->id]);
        $as = $this->actingAs($this->admin, 'sanctum');

        $as->postJson('/api/admin/categories', ['type' => 'project', 'slug' => 'design', 'nameEn' => 'X', 'nameAr' => 'س'])->assertJsonValidationErrors('slug');
        $as->postJson('/api/admin/categories', ['type' => 'project', 'slug' => 'Bad Slug', 'nameEn' => 'X', 'nameAr' => 'س'])->assertJsonValidationErrors('slug');
        $id = $as->postJson('/api/admin/categories', ['type' => 'influencer', 'slug' => 'design', 'nameEn' => 'Design', 'nameAr' => 'تصميم', 'icon' => 'palette', 'sortOrder' => 3])
            ->assertOk()->assertJsonPath('data.projects', 0)->json('data.id');
        $as->patchJson("/api/admin/categories/{$id}", ['nameEn' => 'Graphic design'])->assertOk()
            ->assertJsonPath('data.nameEn', 'Graphic design')->assertJsonPath('data.nameAr', 'تصميم')->assertJsonPath('data.sortOrder', 3);

        $as->getJson('/api/admin/categories?type=project')->assertJsonPath('data.0.projects', 1);
        $as->deleteJson("/api/admin/categories/{$used->id}")->assertJsonPath('code', 'category_in_use');
        $as->deleteJson("/api/admin/categories/{$id}")->assertOk();
        $this->assertNull(Category::find($id));
    }
}
