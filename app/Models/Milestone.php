<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['escrow_id', 'position', 'title', 'amount_cents', 'status', 'due_date', 'fee_cents', 'submission_note', 'changes_note', 'refunded_cents', 'funded_at', 'submitted_at', 'released_at'])]
class Milestone extends Model
{
    use HasFactory;

    /** Waiting for the client to pay it into escrow. */
    public const PENDING = 'pending';

    /** Paid into escrow; the work is under way. */
    public const FUNDED = 'funded';

    /** Delivered; waiting for the client to approve. */
    public const SUBMITTED = 'submitted';

    /** Frozen by a dispute. */
    public const DISPUTED = 'disputed';

    /** Paid out to the freelancer. */
    public const RELEASED = 'released';

    /** Returned to the client. */
    public const REFUNDED = 'refunded';

    /** The statuses in which the money is sitting in escrow. */
    public const HOLDING = [self::FUNDED, self::SUBMITTED, self::DISPUTED];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending', 'fee_cents' => 0, 'refunded_cents' => 0];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'fee_cents' => 'integer',
            'refunded_cents' => 'integer',
            'due_date' => 'date',
            'funded_at' => 'datetime',
            'submitted_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Escrow, $this>
     */
    public function escrow(): BelongsTo
    {
        return $this->belongsTo(Escrow::class);
    }
}
