<?php

namespace App\Policies;

use App\Models\Proposal;
use App\Models\User;

class ProposalPolicy
{
    /** Both sides can read an offer. */
    public function view(User $user, Proposal $proposal): bool
    {
        return $this->isOfferer($user, $proposal) || $this->isProjectOwner($user, $proposal);
    }

    /** Edit or withdraw: the person who made the offer. */
    public function change(User $user, Proposal $proposal): bool
    {
        return $this->isOfferer($user, $proposal);
    }

    /** Accept or decline: the owner of the project. */
    public function answer(User $user, Proposal $proposal): bool
    {
        return $this->isProjectOwner($user, $proposal);
    }

    /**
     * Whether the user sent the proposal.
     */
    private function isOfferer(User $user, Proposal $proposal): bool
    {
        return $user->id === $proposal->user_id;
    }

    /**
     * Whether the user owns the project the proposal was sent to.
     */
    private function isProjectOwner(User $user, Proposal $proposal): bool
    {
        return $user->id === $proposal->project->user_id;
    }
}
