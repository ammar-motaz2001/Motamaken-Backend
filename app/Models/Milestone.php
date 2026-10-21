<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $escrow_id
 * @property int $position
 * @property string $title
 * @property int $amount_cents
 * @property string $status
 * @property Carbon|null $due_date
 * @property int $fee_cents
 * @property string|null $submission_note
 * @property list<string>|null $delivery_links
 * @property string|null $changes_note
 * @property Carbon|null $funded_at
 * @property Carbon|null $submitted_at
 * @property Carbon|null $released_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int $refunded_cents
 * @property-read Escrow $escrow
 *
 * @method static \Database\Factories\MilestoneFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Milestone newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Milestone newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Milestone query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Milestone whereAmountCents($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Milestone whereChangesNote($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Milestone whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Milestone whereDueDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Milestone whereEscrowId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Milestone whereFeeCents($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Milestone whereFundedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Milestone whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Milestone wherePosition($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Milestone whereRefundedCents($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Milestone whereReleasedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Milestone whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Milestone whereSubmissionNote($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Milestone whereSubmittedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Milestone whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Milestone whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['escrow_id', 'position', 'title', 'amount_cents', 'status', 'due_date', 'fee_cents', 'submission_note', 'delivery_links', 'changes_note', 'refunded_cents', 'funded_at', 'submitted_at', 'released_at'])]
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

    /**
     * Money columns are whole cents; the due date and the funding, submission and release times become dates.
     */
    protected function casts(): array
    {
        return ['delivery_links' => 'array',
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
     * The escrow the milestone is paid from.
     *
     * @return BelongsTo<Escrow, $this>
     */
    public function escrow(): BelongsTo
    {
        return $this->belongsTo(Escrow::class);
    }
}
