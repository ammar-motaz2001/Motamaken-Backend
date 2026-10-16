<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\User;

class CampaignPolicy
{
    /** Change, launch, pause, stop and read the visit details: the owner. */
    public function manage(User $user, Campaign $campaign): bool
    {
        return $user->id === $campaign->owner_id;
    }
}
