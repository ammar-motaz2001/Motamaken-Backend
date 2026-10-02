<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $min = fake()->numberBetween(5, 50) * 10;

        return [
            'user_id' => User::factory(),
            'type' => Project::TYPE_PROJECT,
            'category_id' => Category::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'budget_min' => $min,
            'budget_max' => $min + fake()->numberBetween(1, 30) * 10,
            'currency' => 'USD',
            'status' => Project::STATUS_OPEN,
            'is_featured' => false,
        ];
    }

    public function featured(): static
    {
        return $this->state(['is_featured' => true]);
    }

    public function influencerRequest(): static
    {
        return $this->state([
            'type' => Project::TYPE_INFLUENCER_REQUEST,
            'category_id' => Category::factory()->influencer(),
        ]);
    }
}
