<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Proposal>
 */
class ProposalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'user_id' => User::factory(),
            'amount' => fake()->numberBetween(5, 40) * 10,
            'currency' => 'USD',
            'delivery_days' => fake()->numberBetween(2, 30),
            'message' => fake()->paragraph(),
            'status' => Proposal::STATUS_PENDING,
        ];
    }
}
