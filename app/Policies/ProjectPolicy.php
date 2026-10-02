<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    /** Edit, delete, close, complete and read the offers: the owner only. */
    public function manage(User $user, Project $project): bool
    {
        return $user->id === $project->user_id;
    }
}
