<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'proposal_id', 'client_id', 'currency', 'total_cents', 'status'])]
class Escrow extends Model
{
    use HasFactory;

    public const AWAITING_FUNDING = 'awaiting_funding';

    public const ACTIVE = 'active';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'awaiting_funding'];

    protected function casts(): array
    {
        return ['total_cents' => 'integer'];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Proposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    /**
     * @return HasMany<Milestone, $this>
     */
    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class)->orderBy('position');
    }

    /**
     * @return HasMany<EscrowPayee, $this>
     */
    public function payees(): HasMany
    {
        return $this->hasMany(EscrowPayee::class);
    }

    /**
     * Whether the user is the client or one of the people being paid.
     */
    public function involves(User $user): bool
    {
        return $user->id === $this->client_id || $this->payees->contains('user_id', $user->id);
    }

    /**
     * Cents paid into escrow and still there (funded, delivered or disputed milestones).
     */
    public function heldCents(): int
    {
        return (int) $this->milestones->whereIn('status', Milestone::HOLDING)->sum('amount_cents');
    }

    /**
     * Cents paid out to the freelancer(s), before fees: settled milestones minus what went back to the client.
     */
    public function releasedCents(): int
    {
        return (int) $this->milestones->whereIn('status', [Milestone::RELEASED, Milestone::REFUNDED])->sum(
            fn (Milestone $m) => $m->amount_cents - $m->refunded_cents,
        );
    }

    public function refundedCents(): int
    {
        return (int) $this->milestones->whereIn('status', [Milestone::RELEASED, Milestone::REFUNDED])->sum('refunded_cents');
    }
}
