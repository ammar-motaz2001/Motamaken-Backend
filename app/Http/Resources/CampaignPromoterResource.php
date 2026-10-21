<?php

namespace App\Http\Resources;

use App\Models\CampaignPromoter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CampaignPromoter
 */
class CampaignPromoterResource extends JsonResource
{
    /**
     * Returns a promoter's place in a campaign: their link, visits counted and money earned.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** The campaign this link belongs to (in the promoter's own lists). */
            'campaign' => $this->whenLoaded('campaign', fn () => [
                'id' => $this->campaign->id,
                'title' => $this->campaign->title,
                'status' => $this->campaign->status,
                'isRunning' => $this->campaign->isRunning(),
                'rewardPerVisit' => $this->campaign->rewardCents() / 100,
                'currency' => $this->campaign->currency,
            ]),
            /** Who promotes (in the owner's list of promoters). */
            'user' => new UserBriefResource($this->whenLoaded('user')),
            'code' => $this->code,
            /** The link to share. */
            'link' => $this->link(),
            'validVisits' => $this->valid_visits,
            'rejectedVisits' => $this->rejected_visits,
            'earned' => $this->earned_cents / 100,
            'joinedAt' => $this->created_at,
        ];
    }
}
