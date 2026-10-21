<?php

namespace App\Http\Resources;

use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Campaign
 */
class CampaignResource extends JsonResource
{
    /**
     * Returns the campaign with its budget, spending, prices, filters, stats and dates.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            /** `draft`, `active`, `paused`, `completed` (budget used up or ended) or `cancelled`. */
            'status' => $this->status,
            /** True while it is taking paid visits. */
            'isRunning' => $this->isRunning(),
            'owner' => new UserBriefResource($this->whenLoaded('owner')),
            /** Where the links lead: `project` or `profile`, its id, and the website path. */
            'target' => ['type' => $this->target_type, 'id' => $this->target_id, 'path' => $this->targetPath()],
            'currency' => $this->currency,
            'budget' => $this->budget_cents / 100,
            'spent' => $this->spent_cents / 100,
            'remaining' => $this->remainingCents() / 100,
            'costPerVisit' => $this->cost_per_visit_cents / 100,
            /** What a promoter earns per valid visit (the cost minus the platform fee). */
            'rewardPerVisit' => $this->rewardCents() / 100,
            'feePercent' => $this->fee_percent,
            /** The filtering rules: `block_bots`, `unique_window_hours`, `max_visits_per_promoter_per_hour`, `allowed_countries`. */
            'filters' => $this->filters,
            /** The owner's view only: visit totals. */
            'stats' => $this->when(isset($this->visit_stats), fn () => $this->visit_stats),
            'startsAt' => $this->starts_at,
            'endsAt' => $this->ends_at,
            'closedAt' => $this->closed_at,
            'createdAt' => $this->created_at,
        ];
    }
}
