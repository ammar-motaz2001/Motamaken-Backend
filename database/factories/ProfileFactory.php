<?php

namespace Database\Factories;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Profile>
 */
class ProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => Profile::TYPE_FREELANCER,
            'job_title' => fake()->jobTitle(),
            'bio' => fake()->paragraph(),
            'rating' => fake()->randomFloat(1, 3, 5),
            'reviews_count' => fake()->numberBetween(0, 200),
            'is_featured' => false,
        ];
    }

    public function featured(): static
    {
        return $this->state(['is_featured' => true]);
    }

    public function influencer(): static
    {
        return $this->state(['type' => Profile::TYPE_INFLUENCER]);
    }
}
