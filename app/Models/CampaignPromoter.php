<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $campaign_id
 * @property int $user_id
 * @property string $code
 * @property int $valid_visits
 * @property int $rejected_visits
 * @property int $earned_cents
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Campaign $campaign
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CampaignPromoter newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CampaignPromoter newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CampaignPromoter query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CampaignPromoter whereCampaignId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CampaignPromoter whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CampaignPromoter whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CampaignPromoter whereEarnedCents($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CampaignPromoter whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CampaignPromoter whereRejectedVisits($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CampaignPromoter whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CampaignPromoter whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CampaignPromoter whereValidVisits($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['campaign_id', 'user_id', 'code', 'valid_visits', 'rejected_visits', 'earned_cents'])]
class CampaignPromoter extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = ['valid_visits' => 0, 'rejected_visits' => 0, 'earned_cents' => 0];

    /**
     * Visit counters and earnings come back as integers (earnings in cents).
     */
    protected function casts(): array
    {
        return ['valid_visits' => 'integer', 'rejected_visits' => 'integer', 'earned_cents' => 'integer'];
    }

    /**
     * The campaign being promoted.
     *
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * The promoter, paid for the valid visits their link brings.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The link to share: a page of the website that reports the visit and then shows the campaign's target.
     */
    public function link(): string
    {
        return config('auth_flow.frontend_url').config('marketplace.campaigns.landing_path').'/'.$this->code;
    }
}
