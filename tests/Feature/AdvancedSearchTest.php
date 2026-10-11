<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Profile;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdvancedSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_freelancers_are_filtered_by_skill_rating_and_sorted(): void
    {
        $nora = $this->profile('Nora', ['Figma', 'Branding'], 4.8, 3);
        $omar = $this->profile('Omar', ['Laravel', 'Vue'], 4.1, 40);
        $this->profile('Lina', ['تصميم شعارات'], 3.0, 1);

        $this->ids(['skill' => 'figma'])->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $nora->id);
        $this->ids(['skill' => 'شعار'])->assertJsonCount(1, 'data');
        $this->ids(['minRating' => 4])->assertJsonCount(2, 'data');
        $this->ids(['minRating' => 4, 'sort' => 'reviews'])->assertJsonPath('data.0.id', $omar->id);
        $this->ids(['skill' => '50%_'])->assertJsonCount(0, 'data');
    }

    public function test_influencers_are_filtered_by_platform(): void
    {
        $insta = $this->profile('Insta', [], 4, 1, 'influencer', [['platform' => 'instagram', 'handle' => 'a', 'followers' => 10]]);
        $this->profile('Tube', [], 4, 1, 'influencer', [['platform' => 'youtube', 'handle' => 'b', 'followers' => 10]]);

        $this->getJson('/api/search?type=influencers&platform=instagram')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $insta->id);
    }

    public function test_projects_are_filtered_by_category_and_budget_and_sorted(): void
    {
        $design = Category::factory()->create(['slug' => 'design', 'type' => 'project']);
        $small = Project::factory()->create(['category_id' => $design->id, 'budget_min' => 50, 'budget_max' => 100]);
        $big = Project::factory()->create(['category_id' => $design->id, 'budget_min' => 900, 'budget_max' => 2000]);
        Project::factory()->create(['budget_min' => 900, 'budget_max' => 2000]);

        $this->getJson('/api/search?type=projects&category=design')->assertJsonCount(2, 'data');
        $this->getJson('/api/search?type=projects&category=design&budgetMin=500')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $big->id);
        $this->getJson('/api/search?type=projects&category=design&sort=budget_low')->assertJsonPath('data.0.id', $small->id);
        $this->getJson('/api/search?type=projects&budgetMin=500&budgetMax=100')->assertJsonValidationErrors('budgetMax');
    }

    public function test_skill_suggestions_are_the_most_listed(): void
    {
        $this->profile('A', ['Figma', 'Branding'], 4, 1);
        $this->profile('B', ['Figma'], 4, 1);

        $this->getJson('/api/search/skills')->assertJsonPath('data', ['Figma', 'Branding']);
        $this->getJson('/api/search/skills?q=bra')->assertJsonPath('data', ['Branding']);
    }

    private function ids(array $query)
    {
        return $this->getJson('/api/search?'.http_build_query(['type' => 'freelancers', ...$query]));
    }

    /**
     * @param  list<string>  $skills
     * @param  list<array<string, mixed>>  $channels
     */
    private function profile(string $name, array $skills, float $rating, int $reviews, string $type = 'freelancer', array $channels = []): Profile
    {
        return Profile::factory()->create([
            'user_id' => User::factory()->create(['first_name' => $name]),
            'type' => $type, 'skills' => $skills, 'rating' => $rating, 'reviews_count' => $reviews, 'channels' => $channels,
        ]);
    }
}
