<?php

namespace Database\Factories;

use App\Models\Escrow;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Escrow>
 */
class EscrowFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'proposal_id' => Proposal::factory(),
            'client_id' => User::factory(),
            'currency' => 'USD',
            'total_cents' => 100000,
        ];
    }
}
