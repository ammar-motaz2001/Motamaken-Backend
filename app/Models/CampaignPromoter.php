<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['campaign_id', 'user_id', 'code', 'valid_visits', 'rejected_visits', 'earned_cents'])]
class CampaignPromoter extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = ['valid_visits' => 0, 'rejected_visits' => 0, 'earned_cents' => 0];

    protected function casts(): array
    {
        return ['valid_visits' => 'integer', 'rejected_visits' => 'integer', 'earned_cents' => 'integer'];
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
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
