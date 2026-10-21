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
 * @property int $escrow_id
 * @property int $milestone_id
 * @property int $opened_by
 * @property int $against_id
 * @property string $reason
 * @property string $description
 * @property string $status
 * @property string $milestone_status_before
 * @property string|null $resolution
 * @property int|null $client_percent
 * @property string|null $resolution_note
 * @property int|null $assigned_to
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $assignee
 * @property-read Escrow $escrow
 * @property-read Collection<int, DisputeMessage> $messages
 * @property-read int|null $messages_count
 * @property-read Milestone $milestone
 * @property-read User $opener
 * @property-read User $respondent
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute whereAgainstId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute whereAssignedTo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute whereClientPercent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute whereEscrowId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute whereMilestoneId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute whereMilestoneStatusBefore($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute whereOpenedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute whereResolution($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute whereResolutionNote($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute whereResolvedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Dispute whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['escrow_id', 'milestone_id', 'opened_by', 'against_id', 'reason', 'description', 'status', 'milestone_status_before', 'resolution', 'client_percent', 'resolution_note', 'assigned_to', 'resolved_at'])]
class Dispute extends Model
{
    use HasFactory;

    /** Just opened: waiting for an admin. */
    public const OPEN = 'open';

    /** An admin took the case. */
    public const UNDER_REVIEW = 'under_review';

    public const RESOLVED = 'resolved';

    /** The person who opened it took it back. */
    public const WITHDRAWN = 'withdrawn';

    public const REASONS = ['not_delivered', 'quality', 'not_as_described', 'no_response', 'payment', 'other'];

    public const REFUND_CLIENT = 'client_refund';

    public const PAY_FREELANCER = 'freelancer_payout';

    public const SPLIT = 'split';

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'open'];

    /**
     * client_percent (the client's share in the decision) becomes an integer; resolved_at a date.
     */
    protected function casts(): array
    {
        return ['client_percent' => 'integer', 'resolved_at' => 'datetime'];
    }

    /** The statuses in which the milestone is still frozen. */
    public const ACTIVE = [self::OPEN, self::UNDER_REVIEW];

    /**
     * The case number shown to users and admins, e.g. DSP-00042.
     */
    public function caseNumber(): string
    {
        return 'DSP-'.str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Whether the dispute is still open or under review, which keeps its milestone frozen.
     */
    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    /**
     * The escrow the disputed milestone belongs to.
     *
     * @return BelongsTo<Escrow, $this>
     */
    public function escrow(): BelongsTo
    {
        return $this->belongsTo(Escrow::class);
    }

    /**
     * The milestone in dispute, frozen until the case is decided.
     *
     * @return BelongsTo<Milestone, $this>
     */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    /**
     * The party who opened the dispute.
     *
     * @return BelongsTo<User, $this>
     */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /**
     * The other party, against whom the dispute was opened.
     *
     * @return BelongsTo<User, $this>
     */
    public function respondent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'against_id');
    }

    /**
     * The admin handling the case, null until one takes it.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * The case thread: messages, evidence and status notes, oldest first.
     *
     * @return HasMany<DisputeMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(DisputeMessage::class)->orderBy('id');
    }

    /**
     * Which side of the case the user is on: `client`, `freelancer` (anyone being paid), or null for outsiders.
     */
    public function sideOf(User $user): ?string
    {
        $this->loadMissing('escrow.payees');

        return match (true) {
            $user->id === $this->escrow->client_id => 'client',
            $this->escrow->payees->contains('user_id', $user->id) => 'freelancer',
            default => null,
        };
    }

    /**
     * Whether the user is one of the two sides: the client, or someone being paid on the escrow.
     */
    public function involves(User $user): bool
    {
        return in_array($user->id, [$this->opened_by, $this->against_id], true) || $this->escrow->involves($user);
    }
}
