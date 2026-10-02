<?php

namespace App\Http\Resources;

use App\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Promotion
 */
class PromotionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** `profile` (Featured profiles slider) or `project` (Distinctive projects slider). */
            'kind' => $this->kind,
            'project' => $this->whenLoaded('project', fn () => $this->project ? ['id' => $this->project->id, 'title' => $this->project->title] : null),
            'days' => $this->days,
            'price' => $this->price_cents / 100,
            'currency' => $this->currency,
            'startsAt' => $this->starts_at,
            /** When the placement ends (extended if bought again while active). */
            'endsAt' => $this->ends_at,
            'isActive' => $this->ends_at->isFuture(),
        ];
    }
}
