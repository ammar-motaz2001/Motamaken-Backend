<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $amount_cents
 * @property int $fee_cents
 * @property string $currency
 * @property string $method
 * @property array<array-key, mixed> $destination
 * @property string $status
 * @property string|null $payout_reference
 * @property string|null $reject_reason
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Withdrawal newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Withdrawal newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Withdrawal query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Withdrawal whereAmountCents($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Withdrawal whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Withdrawal whereCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Withdrawal whereDestination($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Withdrawal whereFeeCents($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Withdrawal whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Withdrawal whereMethod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Withdrawal wherePayoutReference($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Withdrawal whereRejectReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Withdrawal whereReviewedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Withdrawal whereReviewedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Withdrawal whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Withdrawal whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Withdrawal whereUserId($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['user_id', 'amount_cents', 'fee_cents', 'currency', 'method', 'destination', 'status', 'payout_reference', 'reject_reason', 'reviewed_by', 'reviewed_at'])]
class Withdrawal extends Model
{
    use HasFactory;

    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending', 'fee_cents' => 0];

    /**
     * Amount and fee are whole cents; the payout destination is stored as an encrypted array.
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'fee_cents' => 'integer',
            'destination' => 'encrypted:array',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * The user taking money out of their wallet.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether the withdrawal still waits for an admin's review.
     */
    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
