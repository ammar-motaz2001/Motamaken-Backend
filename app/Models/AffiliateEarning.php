<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $referrer_id
 * @property int $referred_id
 * @property int $milestone_id
 * @property float $rate_percent
 * @property int $amount_cents
 * @property string $currency
 * @property Carbon $created_at
 * @property-read Milestone $milestone
 * @property-read User $referred
 * @property-read User $referrer
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AffiliateEarning newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AffiliateEarning newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AffiliateEarning query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AffiliateEarning whereAmountCents($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AffiliateEarning whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AffiliateEarning whereCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AffiliateEarning whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AffiliateEarning whereMilestoneId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AffiliateEarning whereRatePercent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AffiliateEarning whereReferredId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AffiliateEarning whereReferrerId($value)
 *
 * @mixin \Eloquent
 */
class AffiliateEarning extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * The amount is whole cents, the rate a percentage as a float.
     */
    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'rate_percent' => 'float'];
    }

    /**
     * The user who shared the invite link and earns the commission.
     *
     * @return BelongsTo<User, $this>
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    /**
     * The invited user whose payment produced the commission.
     *
     * @return BelongsTo<User, $this>
     */
    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_id');
    }

    /**
     * The released milestone the commission was taken from.
     *
     * @return BelongsTo<Milestone, $this>
     */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }
}
