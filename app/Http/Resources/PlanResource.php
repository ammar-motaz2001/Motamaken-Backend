<?php

namespace App\Http\Resources;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Plan
 */
class PlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            /** `freelancer`, `client` or `influencer`: which tab of the plans page it belongs to. */
            'audience' => $this->audience,
            'name' => $this->name,
            /** Monthly price. 0 for the free plan. */
            'price' => $this->price_cents / 100,
            'currency' => $this->currency,
            'isFree' => $this->isFree(),
            /** The "Most popular" ribbon. */
            'isPopular' => $this->is_popular,
            /** The benefits as sentences in the request language, ready for the checklist on the card. */
            'perks' => $this->perks(),
            /** The same benefits as numbers: `monthly_offers` (null = unlimited), `fee_percent`, `promotion_discount_percent`. */
            'features' => $this->features,
            /** Admin lists only: how many people are on the plan right now. */
            'subscribers' => $this->when(isset($this->subscribers), fn () => $this->subscribers),
            /** True on the plan the logged-in user is on. */
            'isCurrent' => $this->when(isset($this->is_current), fn () => $this->is_current),
        ];
    }
}
