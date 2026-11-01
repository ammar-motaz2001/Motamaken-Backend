<?php

namespace App\Console\Commands;

use App\Models\Milestone;
use App\Services\Payments\EscrowService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('escrow:release-holds')]
#[Description('Move payouts whose guarantee period ended from hold to the freelancers\' wallets')]
class ReleaseGuaranteeHolds extends Command
{
    /**
     * Release every milestone whose guarantee period has passed and print how many.
     */
    public function handle(EscrowService $escrows): int
    {
        $due = Milestone::whereNotNull('held_until')->whereNull('hold_released_at')->where('held_until', '<=', now())->get();
        $due->each(fn (Milestone $milestone) => $escrows->releaseHold($milestone));
        $this->info("Released {$due->count()} payout(s) from the guarantee period.");

        return self::SUCCESS;
    }
}
