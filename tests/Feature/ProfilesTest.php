<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfilesTest extends TestCase
{
    use RefreshDatabase;

    private User $freelancer;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freelancer = User::factory()->create(['first_name' => 'Nora', 'last_name' => 'Dev']);
        $this->client = User::factory()->create(['first_name' => 'Cleo', 'last_name' => 'Client']);
        Profile::factory()->create(['user_id' => $this->freelancer->id, 'type' => 'freelancer', 'job_title' => 'Designer']);
    }

    public function test_profile_sections_are_saved_and_kept_when_left_out(): void
    {
        $base = ['type' => 'freelancer', 'jobTitle' => 'Brand Designer'];
        $this->as($this->freelancer)->putJson('/api/me/profile', [...$base,
            'skills' => ['Figma', 'Branding'],
            'languages' => [['name' => 'Arabic', 'level' => 'native']],
            'experiences' => [['title' => 'Designer', 'company' => 'Acme', 'from' => '2021-03', 'to' => null, 'description' => 'Logos']],
            'educations' => [['school' => 'Cairo University', 'degree' => 'BA Arts', 'from' => 2015, 'to' => 2019]],
            'certificates' => [['name' => 'UX', 'issuer' => 'Google', 'year' => 2022, 'url' => 'https://example.com/c']],
        ])->assertOk()->assertJsonPath('data.skills', ['Figma', 'Branding'])->assertJsonPath('data.experiences.0.company', 'Acme');

        $this->as($this->freelancer)->putJson('/api/me/profile', $base)->assertOk()->assertJsonPath('data.skills', ['Figma', 'Branding']);
        $this->as($this->freelancer)->putJson('/api/me/profile', [...$base, 'skills' => []])->assertOk()->assertJsonPath('data.skills', []);
        $this->as($this->freelancer)->putJson('/api/me/profile', [...$base,
            'languages' => [['name' => 'English', 'level' => 'expert']],
            'experiences' => [['title' => 'X', 'company' => 'Y', 'from' => '2023-05', 'to' => '2022-01']],
            'channels' => [['platform' => 'myspace', 'handle' => 'me', 'followers' => -1]],
        ])->assertJsonValidationErrors(['languages.0.level', 'experiences.0.to', 'channels.0.platform', 'channels.0.followers']);
    }

    public function test_portfolio_items_can_be_added_edited_and_removed(): void
    {
        Storage::fake('public');

        $id = $this->as($this->freelancer)->post('/api/me/portfolio', ['title' => 'Coffee brand', 'link' => 'https://example.com', 'image' => $this->png()], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.title', 'Coffee brand')->json('data.id');
        $this->as($this->freelancer)->patchJson("/api/me/portfolio/{$id}", ['title' => 'Coffee shop brand'])->assertOk()->assertJsonPath('data.title', 'Coffee shop brand');
        $this->getJson("/api/users/{$this->freelancer->id}")->assertJsonPath('profile.portfolio.0.title', 'Coffee shop brand');

        $this->as($this->client)->deleteJson("/api/me/portfolio/{$id}")->assertNotFound();
        $this->as($this->client)->post('/api/me/portfolio', ['title' => 'Nope', 'image' => $this->png()], ['Accept' => 'application/json'])->assertJsonPath('code', 'profile_required');
        $this->as($this->freelancer)->deleteJson("/api/me/portfolio/{$id}")->assertOk();
    }

    public function test_the_client_reviews_the_freelancer_replies_and_the_rating_updates(): void
    {
        $project = $this->completedProject();

        $this->getJson("/api/projects/{$project->id}/reviews")->assertJsonPath('canReview', false);
        $client = $this->client->createToken('web')->plainTextToken;
        $this->withToken($client)->getJson("/api/projects/{$project->id}/reviews")->assertJsonPath('canReview', true);
        $this->app['auth']->forgetGuards();
        $this->as($this->client)->postJson("/api/projects/{$project->id}/reviews", ['rating' => 4, 'comment' => 'Great logo'])->assertCreated();
        $this->as($this->client)->postJson("/api/projects/{$project->id}/reviews", ['rating' => 5])->assertJsonPath('code', 'review_exists');
        $this->as($this->freelancer)->postJson("/api/projects/{$project->id}/reviews", ['rating' => 5, 'comment' => 'Clear brief'])->assertJsonPath('code', 'review_reply_only');
        $reviewId = $this->as($this->freelancer)->getJson("/api/projects/{$project->id}/reviews")->assertJsonPath('canReply', true)->json('data.0.id');
        $this->as($this->client)->postJson("/api/reviews/{$reviewId}/reply", ['reply' => 'Not mine'])->assertForbidden();
        $this->as($this->freelancer)->postJson("/api/reviews/{$reviewId}/reply", ['reply' => 'Thanks, clear brief'])->assertOk()->assertJsonPath('data.reply', 'Thanks, clear brief');
        $this->as($this->freelancer)->postJson("/api/reviews/{$reviewId}/reply", ['reply' => 'Again'])->assertJsonPath('code', 'reply_exists');
        $stranger = User::factory()->create();
        $this->as($stranger)->postJson("/api/projects/{$project->id}/reviews", ['rating' => 1])->assertForbidden();

        $this->assertSame(4.0, $this->freelancer->profile->fresh()->rating);
        $this->assertSame(1, $this->freelancer->profile->fresh()->reviews_count);
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/users/{$this->freelancer->id}")->assertOk()
            ->assertJsonPath('stats.completedJobs', 1)->assertJsonPath('stats.rating', 4)->assertJsonPath('reviews.0.comment', 'Great logo')
            ->assertJsonPath('profile.jobTitle', 'Designer')->assertJsonPath('isMe', false);
        $this->getJson("/api/users/{$this->client->id}")->assertJsonPath('profile', null)->assertJsonPath('stats.completedProjects', 1)->assertJsonPath('stats.reviewsCount', 0);
        $this->getJson("/api/users/{$this->freelancer->id}/reviews")->assertJsonPath('average', 4)->assertJsonPath('total', 1);
        $this->assertContains('review.received', $this->as($this->freelancer)->getJson('/api/notifications')->json('data.*.type'));
    }

    public function test_reviews_need_a_completed_project(): void
    {
        $project = $this->completedProject();
        $project->update(['status' => Project::STATUS_IN_PROGRESS]);

        $this->as($this->client)->postJson("/api/projects/{$project->id}/reviews", ['rating' => 5])->assertForbidden();
        $this->as($this->client)->getJson("/api/projects/{$project->id}/reviews")->assertJsonPath('canReview', false);
    }

    public function test_content_can_be_reported_once_and_listed(): void
    {
        $body = ['targetType' => 'user', 'targetId' => $this->freelancer->id, 'reason' => 'fake'];

        $this->as($this->client)->postJson('/api/reports', $body)->assertCreated()->assertJsonPath('data.status', 'open');
        $this->as($this->client)->postJson('/api/reports', $body)->assertJsonPath('code', 'already_reported');
        $this->as($this->freelancer)->postJson('/api/reports', $body)->assertJsonPath('code', 'report_self');
        $this->as($this->client)->postJson('/api/reports', [...$body, 'targetId' => 999999])->assertNotFound();
        $this->as($this->client)->postJson('/api/reports', ['targetType' => 'project', 'targetId' => 1, 'reason' => 'other'])->assertJsonValidationErrors('details');

        $this->as($this->client)->getJson('/api/me/reports')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.reason', 'fake')->assertJsonPath('counts.open', 1);
    }

    private function completedProject(): Project
    {
        $project = Project::factory()->create(['user_id' => $this->client->id, 'category_id' => Category::factory()]);
        $proposal = Proposal::factory()->create(['project_id' => $project->id, 'user_id' => $this->freelancer->id, 'status' => 'accepted']);
        $project->update(['status' => Project::STATUS_COMPLETED, 'accepted_proposal_id' => $proposal->id]);

        return $project;
    }

    private function png(): UploadedFile
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $bytes = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 200, 150, 8, 2, 0, 0, 0)).$chunk('IDAT', gzcompress(str_repeat("\0".str_repeat("\x18\x8d\x4a", 200), 150))).$chunk('IEND', '');

        return UploadedFile::fake()->createWithContent('work.png', $bytes);
    }

    private function as(User $user): static
    {
        return $this->actingAs($user, 'sanctum');
    }
}
