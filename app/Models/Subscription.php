<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
