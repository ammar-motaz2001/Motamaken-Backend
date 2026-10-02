<?php

namespace Tests\Concerns;

use App\Models\Account;
use App\Models\Category;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use App\Services\Payments\LedgerService;

/**
 * Test helpers for anything that touches money.
 */
trait HandlesMoney
{
    /**
     * Put money in a user's wallet the way a real deposit does.
     */
    protected function fund(User $user, float|int $amount, string $currency = 'USD'): void
    {
        $ledger = app(LedgerService::class);
        $cents = (int) round($amount * 100);

        $ledger->post('deposit', [
            [$ledger->system(Account::GATEWAY, $currency), -$cents],
            [$ledger->wallet($user, $currency), $cents],
        ]);
    }

    protected function balance(User|string $owner, string $currency = 'USD'): float
    {
        $account = $owner instanceof User
            ? app(LedgerService::class)->wallet($owner, $currency)
            : app(LedgerService::class)->system($owner, $currency);

        return $account->refresh()->balance_cents / 100;
    }

    /**
     * A project that is already hired: an accepted offer from the freelancer, project in progress.
     */
    protected function hiredProject(User $client, User $freelancer, float|int $price = 1000, string $currency = 'USD'): Project
    {
        Profile::firstOrCreate(['user_id' => $freelancer->id], Profile::factory()->raw(['user_id' => $freelancer->id]));

        $project = Project::factory()->create([
            'user_id' => $client->id, 'category_id' => Category::factory(), 'currency' => $currency,
            'status' => Project::STATUS_IN_PROGRESS,
        ]);
        $proposal = Proposal::factory()->create([
            'project_id' => $project->id, 'user_id' => $freelancer->id, 'amount' => $price,
            'currency' => $currency, 'status' => Proposal::STATUS_ACCEPTED,
        ]);
        $project->update(['accepted_proposal_id' => $proposal->id]);

        return $project;
    }

    /**
     * The sum of every account must be zero: money is never created or lost.
     */
    protected function assertBooksBalance(): void
    {
        $this->assertSame(0, (int) Account::sum('balance_cents'), 'the ledger does not balance');
    }
}
