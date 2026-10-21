<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int $proposal_id
 * @property int $client_id
 * @property string $currency
 * @property int $total_cents
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $client
 * @property-read Collection<int, Milestone> $milestones
 * @property-read int|null $milestones_count
 * @property-read Collection<int, EscrowPayee> $payees
 * @property-read int|null $payees_count
 * @property-read Project $project
 * @property-read Proposal $proposal
 * @property-read Collection<int, Tip> $tips
 * @property-read int|null $tips_count
 *
 * @method static \Database\Factories\EscrowFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Escrow newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Escrow newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Escrow query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Escrow whereClientId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Escrow whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Escrow whereCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Escrow whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Escrow whereProjectId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Escrow whereProposalId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Escrow whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Escrow whereTotalCents($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Escrow whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
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

    /**
     * The total is whole cents.
     */
    protected function casts(): array
    {
        return ['total_cents' => 'integer'];
    }

    /**
     * The project the work is for.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The accepted proposal the escrow was created from.
     *
     * @return BelongsTo<Proposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    /**
     * The client who funds the milestones.
     *
     * @return BelongsTo<User, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    /**
     * The milestones, in their agreed order.
     *
     * @return HasMany<Milestone, $this>
     */
    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class)->orderBy('position');
    }

    /**
     * The people being paid: one freelancer, or each member of a team.
     *
     * @return HasMany<EscrowPayee, $this>
     */
    public function payees(): HasMany
    {
        return $this->hasMany(EscrowPayee::class);
    }

    /**
     * Extra amounts the client gave on top of the milestones.
     *
     * @return HasMany<Tip, $this>
     */
    public function tips(): HasMany
    {
        return $this->hasMany(Tip::class);
    }

    /** Every member being paid confirmed their share (always true for a solo freelancer). */
    public function splitApproved(): bool
    {
        return $this->payees->every(fn (EscrowPayee $payee) => $payee->split_status === EscrowPayee::SPLIT_APPROVED);
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

    /**
     * Cents returned to the client from released or refunded milestones.
     */
    public function refundedCents(): int
    {
        return (int) $this->milestones->whereIn('status', [Milestone::RELEASED, Milestone::REFUNDED])->sum('refunded_cents');
    }
}
