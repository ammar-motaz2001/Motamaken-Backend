<?php

namespace App\Policies;

use App\Models\Escrow;
use App\Models\User;

class EscrowPolicy
{
    /** The client and everyone being paid can read it. */
    public function view(User $user, Escrow $escrow): bool
    {
        return $escrow->involves($user);
    }

    /** Fund, approve, ask for changes, cancel: the client. */
    public function client(User $user, Escrow $escrow): bool
    {
        return $user->id === $escrow->client_id;
    }

    /** Open or give in on a dispute: the client, or the lead of the people being paid (a team speaks with one voice). */
    public function dispute(User $user, Escrow $escrow): bool
    {
        return $this->client($user, $escrow) || $this->deliver($user, $escrow);
    }

    /** Deliver work: the lead of the people being paid (the freelancer, or the team lead). */
    public function deliver(User $user, Escrow $escrow): bool
    {
        return $escrow->payees->contains(fn ($payee) => $payee->user_id === $user->id && $payee->is_lead);
    }
}
