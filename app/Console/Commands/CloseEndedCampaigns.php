<?php

namespace App\Console\Commands;

use App\Services\CampaignService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('campaigns:close-ended')]
#[Description('Close the campaigns whose end date passed and refund what is left of their budgets')]
class CloseEndedCampaigns extends Command
{
    /**
     * Close the campaigns whose end date has passed and print how many were closed.
     */
    public function handle(CampaignService $campaigns): int
    {
        $this->info("Closed {$campaigns->closeEnded()} campaign(s).");

        return self::SUCCESS;
    }
}
