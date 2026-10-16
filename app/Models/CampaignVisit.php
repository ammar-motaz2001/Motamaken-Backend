<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignVisit extends Model
{
    public const UPDATED_AT = null;

    public const VALID = 'valid';

    public const REJECTED = 'rejected';

    public const REASONS = ['bot', 'duplicate', 'rate_limited', 'country', 'own_link', 'budget_exhausted', 'not_running'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['reward_cents' => 'integer'];
    }

    /**
     * @return BelongsTo<CampaignPromoter, $this>
     */
    public function promoter(): BelongsTo
    {
        return $this->belongsTo(CampaignPromoter::class, 'promoter_id');
    }
}
