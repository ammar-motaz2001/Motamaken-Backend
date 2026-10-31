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

    /**
     * Seeing a campaign's stats, promoters and visits: its owner, and staff who moderate the marketplace.
     */
    public function view(User $user, Campaign $campaign): bool
    {
        return $user->id === $campaign->owner_id || ($user->is_admin && $user->canAdmin('marketplace'));
    }
}
