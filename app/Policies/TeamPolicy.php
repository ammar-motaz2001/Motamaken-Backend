<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    /** The lead and everyone invited (they need to see what they are asked to join). */
    public function view(User $user, Team $team): bool
    {
        return $user->id === $team->owner_id || $team->members()->where('user_id', $user->id)->exists();
    }

    /** Change, invite, publish, disband: the lead. */
    public function lead(User $user, Team $team): bool
    {
        return $user->id === $team->owner_id;
    }
}
