<?php

namespace App\Http\Resources;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Subscription
 */
class SubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plan' => new PlanResource($this->plan),
            /** `active`, `past_due` (the renewal failed; benefits continue for a few days), `cancelled` or `expired`. */
            'status' => $this->status,
            /** False after the user cancelled: the plan runs until the end of the period. */
            'autoRenew' => $this->auto_renew,
            'currentPeriodStart' => $this->current_period_start,
            /** The plan renews or ends on this date. */
            'currentPeriodEnd' => $this->current_period_end,
            'cancelledAt' => $this->cancelled_at,
        ];
    }
}
