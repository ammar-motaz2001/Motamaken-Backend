<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $escrow_id
 * @property int $user_id
 * @property int $share_bp
 * @property bool $is_lead
 * @property string $split_status
 * @property string|null $split_note
 * @property Carbon|null $split_answered_at
 * @property-read Escrow $escrow
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EscrowPayee newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EscrowPayee newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EscrowPayee query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EscrowPayee whereEscrowId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EscrowPayee whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EscrowPayee whereIsLead($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EscrowPayee whereShareBp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EscrowPayee whereSplitAnsweredAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EscrowPayee whereSplitNote($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EscrowPayee whereSplitStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EscrowPayee whereUserId($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['escrow_id', 'user_id', 'share_bp', 'is_lead', 'split_status', 'split_note', 'split_answered_at'])]
class EscrowPayee extends Model
{
    public const SPLIT_PENDING = 'pending';

    public const SPLIT_APPROVED = 'approved';

    public const SPLIT_OBJECTED = 'objected';

    public $timestamps = false;

    /**
     * The share is in basis points, is_lead a boolean, split_answered_at a date.
     */
    protected function casts(): array
    {
        return ['share_bp' => 'integer', 'is_lead' => 'boolean', 'split_answered_at' => 'datetime'];
    }

    /**
     * The user being paid.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The escrow they are paid from.
     *
     * @return BelongsTo<Escrow, $this>
     */
    public function escrow(): BelongsTo
    {
        return $this->belongsTo(Escrow::class);
    }
}
