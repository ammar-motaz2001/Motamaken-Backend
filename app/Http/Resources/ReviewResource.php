<?php

namespace App\Http\Resources;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Review
 */
class ReviewResource extends JsonResource
{
    /**
     * Returns the rating, comment, reviewer and project of the review.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** 1 to 5 stars. */
            'rating' => $this->rating,
            'comment' => $this->comment,
            'reviewer' => new UserBriefResource($this->whenLoaded('reviewer')),
            'revieweeId' => $this->reviewee_id,
            'project' => $this->whenLoaded('project', fn () => ['id' => $this->project->id, 'title' => $this->project->title]),
            'createdAt' => $this->created_at,
        ];
    }
}
