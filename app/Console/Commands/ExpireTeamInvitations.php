<?php

namespace App\Console\Commands;

use App\Services\TeamService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('teams:expire-invitations')]
#[Description('Expire team invitations nobody answered in time')]
class ExpireTeamInvitations extends Command
{
    /**
     * Expire team invitations nobody answered in time and print how many.
     */
    public function handle(TeamService $teams): int
    {
        $this->info("Expired {$teams->expireInvitations()} invitation(s).");

        return self::SUCCESS;
    }
}
