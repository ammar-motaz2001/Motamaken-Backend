<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $dispute_id
 * @property int $user_id
 * @property string $reason
 * @property string $status
 * @property string|null $decision_note
 * @property int|null $adjustment_cents
 * @property string|null $adjustment_to
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $decider
 * @property-read Dispute $dispute
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DisputeAppeal newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DisputeAppeal newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DisputeAppeal query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DisputeAppeal whereAdjustmentCents($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DisputeAppeal whereAdjustmentTo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DisputeAppeal whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DisputeAppeal whereDecidedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DisputeAppeal whereDecidedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DisputeAppeal whereDecisionNote($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DisputeAppeal whereDisputeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DisputeAppeal whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DisputeAppeal whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DisputeAppeal whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DisputeAppeal whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DisputeAppeal whereUserId($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['dispute_id', 'user_id', 'reason', 'status', 'decision_note', 'adjustment_cents', 'adjustment_to', 'decided_by', 'decided_at'])]
class DisputeAppeal extends Model
{
    public const PENDING = 'pending';

    public const UPHELD = 'upheld';

    public const OVERTURNED = 'overturned';

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::PENDING];

    /**
     * decided_at becomes a date; the adjustment is whole cents.
     */
    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'adjustment_cents' => 'integer'];
    }

    /**
     * The dispute whose decision is appealed.
     *
     * @return BelongsTo<Dispute, $this>
     */
    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }

    /**
     * The party who filed the appeal.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The admin who decided the appeal, null while pending.
     *
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
