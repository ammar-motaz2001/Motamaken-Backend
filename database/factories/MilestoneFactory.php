<?php

namespace Database\Factories;

use App\Models\Escrow;
use App\Models\Milestone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Milestone>
 */
class MilestoneFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'escrow_id' => Escrow::factory(),
            'position' => 1,
            'title' => fake()->words(2, true),
            'amount_cents' => 10000,
        ];
    }
}
