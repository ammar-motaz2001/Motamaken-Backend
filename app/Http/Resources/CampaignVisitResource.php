<?php

namespace App\Http\Resources;

use App\Models\CampaignVisit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One visit on the filtering screen.
 *
 * @mixin CampaignVisit
 */
class CampaignVisitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** `valid` (paid) or `rejected`. */
            'status' => $this->status,
            /** Why it was not paid: `bot`, `duplicate`, `rate_limited`, `country`, `own_link`, `budget_exhausted` or `not_running`. */
            'rejectReason' => $this->reject_reason,
            /** The reason in the request language. */
            'rejectLabel' => $this->reject_reason ? __("campaigns.reasons.{$this->reject_reason}") : null,
            'promoter' => $this->whenLoaded('promoter', fn () => ['id' => $this->promoter->id, 'fullName' => $this->promoter->user->full_name]),
            /** A short fingerprint of the visitor (never the address itself), to spot repeats. */
            'visitor' => substr($this->ip_hash, 0, 10),
            'country' => $this->country,
            'referrer' => $this->referrer_host,
            'reward' => $this->reward_cents / 100,
            'createdAt' => $this->created_at,
        ];
    }
}
