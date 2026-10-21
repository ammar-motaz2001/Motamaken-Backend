<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $plan_id
 * @property string $status
 * @property bool $auto_renew
 * @property Carbon $current_period_start
 * @property Carbon $current_period_end
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Plan $plan
 * @property-read User $user
 *
 * @method static Builder<static>|Subscription inEffect()
 * @method static Builder<static>|Subscription newModelQuery()
 * @method static Builder<static>|Subscription newQuery()
 * @method static Builder<static>|Subscription query()
 * @method static Builder<static>|Subscription whereAutoRenew($value)
 * @method static Builder<static>|Subscription whereCancelledAt($value)
 * @method static Builder<static>|Subscription whereCreatedAt($value)
 * @method static Builder<static>|Subscription whereCurrentPeriodEnd($value)
 * @method static Builder<static>|Subscription whereCurrentPeriodStart($value)
 * @method static Builder<static>|Subscription whereId($value)
 * @method static Builder<static>|Subscription wherePlanId($value)
 * @method static Builder<static>|Subscription whereStatus($value)
 * @method static Builder<static>|Subscription whereUpdatedAt($value)
 * @method static Builder<static>|Subscription whereUserId($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['user_id', 'plan_id', 'status', 'auto_renew', 'current_period_start', 'current_period_end', 'cancelled_at'])]
class Subscription extends Model
{
    use HasFactory;

    public const ACTIVE = 'active';

    /** The renewal payment failed; the benefits last through a grace period. */
    public const PAST_DUE = 'past_due';

    /** Replaced by another plan. */
    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'active', 'auto_renew' => true];

    /**
     * auto_renew becomes a boolean; the period and cancellation times dates.
     */
    protected function casts(): array
    {
        return [
            'auto_renew' => 'boolean',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Subscriptions that still give their benefits: inside the paid period, or (when they renew by themselves) through
     * the grace period after a missed renewal.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInEffect(Builder $query): Builder
    {
        $grace = now()->subDays(config('marketplace.subscriptions.grace_days'));

        return $query->whereIn('status', [self::ACTIVE, self::PAST_DUE])->where(
            fn (Builder $q) => $q->where('current_period_end', '>', now())
                ->orWhere(fn (Builder $r) => $r->where('auto_renew', true)->where('current_period_end', '>', $grace)),
        );
    }

    /**
     * The subscriber.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The plan subscribed to.
     *
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
