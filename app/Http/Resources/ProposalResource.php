<?php

namespace App\Http\Resources;

use App\Models\Proposal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Proposal
 */
class ProposalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'projectId' => $this->project_id,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'deliveryDays' => $this->delivery_days,
            /** The cover letter. */
            'message' => $this->message,
            /** `pending`, `accepted`, `declined` or `withdrawn`. */
            'status' => $this->status,
            /** Who made the offer, with their profile details when they have a profile. */
            'freelancer' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'fullName' => $this->user->full_name,
                'username' => $this->user->username,
                'avatarUrl' => $this->user->avatar_url,
                'isOnline' => $this->user->isOnline(),
                'jobTitle' => $this->user->profile?->job_title,
                'rating' => $this->user->profile?->rating,
                'reviewsCount' => $this->user->profile?->reviews_count,
            ]),
            /** Set when the offer was made for a team: its name and the members who share the payment. */
            'team' => $this->whenLoaded('team', fn () => $this->team ? [
                'id' => $this->team->id,
                'name' => $this->team->name,
                'members' => $this->team->members->where('status', 'accepted')->map(fn ($m) => [
                    'userId' => $m->user_id, 'fullName' => $m->user->full_name, 'roleTitle' => $m->role_title, 'sharePercent' => $m->share_bp / 100,
                ])->values(),
            ] : null),
            /** The project the offer is on ("My Offers" list). */
            'project' => new ProjectResource($this->whenLoaded('project')),
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
