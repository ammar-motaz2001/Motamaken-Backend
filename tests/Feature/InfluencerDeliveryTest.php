<?php

namespace Tests\Feature;

use App\Models\Milestone;
use App\Models\Profile;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HandlesMoney;
use Tests\TestCase;

class InfluencerDeliveryTest extends TestCase
{
    use HandlesMoney, RefreshDatabase;

    public function test_an_influencer_delivers_with_links_to_the_published_posts(): void
    {
        $client = User::factory()->create();
        $influencer = User::factory()->create();
        $project = $this->hiredProject($client, $influencer, 500);
        $project->update(['type' => Project::TYPE_INFLUENCER_REQUEST]);
        $this->fund($client, 500);
        $this->actingAs($client, 'sanctum')->postJson("/api/projects/{$project->id}/escrow", ['milestones' => [['title' => 'Campaign posts', 'amount' => 500]]])->assertCreated();
        $milestone = Milestone::first();
        $this->actingAs($client, 'sanctum')->postJson("/api/milestones/{$milestone->id}/fund")->assertOk();

        $this->actingAs($influencer, 'sanctum')->postJson("/api/milestones/{$milestone->id}/submit", ['note' => 'Posted on both accounts.'])
            ->assertStatus(422)->assertJsonValidationErrors('links');
        $this->actingAs($influencer, 'sanctum')->postJson("/api/milestones/{$milestone->id}/submit", ['links' => ['http://insecure.example/post']])
            ->assertStatus(422);

        $links = ['https://www.instagram.com/p/abc123/', 'https://x.com/someone/status/1'];
        $this->actingAs($influencer, 'sanctum')->postJson("/api/milestones/{$milestone->id}/submit", ['note' => 'Posted on both accounts.', 'links' => $links])
            ->assertOk()->assertJsonPath('data.deliveryLinks', $links)->assertJsonPath('data.status', 'submitted');
    }

    public function test_other_projects_can_deliver_without_links(): void
    {
        $client = User::factory()->create();
        $freelancer = User::factory()->create();
        $project = $this->hiredProject($client, $freelancer, 300);
        $this->fund($client, 300);
        $this->actingAs($client, 'sanctum')->postJson("/api/projects/{$project->id}/escrow", ['milestones' => [['title' => 'Landing page', 'amount' => 300]]])->assertCreated();
        $milestone = Milestone::first();
        $this->actingAs($client, 'sanctum')->postJson("/api/milestones/{$milestone->id}/fund")->assertOk();

        $this->actingAs($freelancer, 'sanctum')->postJson("/api/milestones/{$milestone->id}/submit")->assertOk()->assertJsonPath('data.deliveryLinks', []);
    }

    public function test_profile_cards_show_whether_the_identity_is_verified(): void
    {
        $verified = User::factory()->create(['kyc_status' => 'approved']);
        Profile::factory()->create(['user_id' => $verified->id, 'type' => 'influencer']);
        $other = User::factory()->create();
        Profile::factory()->create(['user_id' => $other->id, 'type' => 'influencer']);

        $cards = collect($this->getJson('/api/search?type=influencers')->assertOk()->json('data'));
        $this->assertTrue($cards->firstWhere('userId', $verified->id)['verified']);
        $this->assertFalse($cards->firstWhere('userId', $other->id)['verified']);
    }
}
