<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $campaign_id
 * @property int $promoter_id
 * @property string $ip_hash
 * @property string|null $country
 * @property string|null $referrer_host
 * @property string $status
 * @property string|null $reject_reason
 * @property int $reward_cents
 * @property Carbon $created_at
 * @property-read CampaignPromoter $promoter
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CampaignVisit newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CampaignVisit newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CampaignVisit query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CampaignVisit whereCampaignId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CampaignVisit whereCountry($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CampaignVisit whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CampaignVisit whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CampaignVisit whereIpHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CampaignVisit wherePromoterId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CampaignVisit whereReferrerHost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CampaignVisit whereRejectReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CampaignVisit whereRewardCents($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CampaignVisit whereStatus($value)
 *
 * @mixin \Eloquent
 */
class CampaignVisit extends Model
{
    public const UPDATED_AT = null;

    public const VALID = 'valid';

    public const REJECTED = 'rejected';

    public const REASONS = ['bot', 'duplicate', 'rate_limited', 'country', 'own_link', 'budget_exhausted', 'not_running'];

    protected $guarded = [];

    /**
     * The reward paid for the visit, in whole cents.
     */
    protected function casts(): array
    {
        return ['reward_cents' => 'integer'];
    }

    /**
     * The promoter whose link brought the visit.
     *
     * @return BelongsTo<CampaignPromoter, $this>
     */
    public function promoter(): BelongsTo
    {
        return $this->belongsTo(CampaignPromoter::class, 'promoter_id');
    }
}
