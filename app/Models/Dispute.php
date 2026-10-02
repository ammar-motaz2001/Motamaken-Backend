<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    protected function casts(): array
    {
        return ['client_percent' => 'integer', 'resolved_at' => 'datetime'];
    }

    /** The statuses in which the milestone is still frozen. */
    public const ACTIVE = [self::OPEN, self::UNDER_REVIEW];

    public function caseNumber(): string
    {
        return 'DSP-'.str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    /**
     * @return BelongsTo<Escrow, $this>
     */
    public function escrow(): BelongsTo
    {
        return $this->belongsTo(Escrow::class);
    }

    /**
     * @return BelongsTo<Milestone, $this>
     */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function respondent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'against_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
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
