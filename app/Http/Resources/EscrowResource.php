<?php

namespace App\Http\Resources;

use App\Models\Escrow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Escrow
 */
class EscrowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->loadMissing(['milestones', 'payees.user']);

        return [
            'id' => $this->id,
            'projectId' => $this->project_id,
            'currency' => $this->currency,
            /** `awaiting_funding`, `active` (money in escrow), `completed`. */
            'status' => $this->status,
            'client' => new UserBriefResource($this->whenLoaded('client')),
            'project' => $this->whenLoaded('project', fn () => ['id' => $this->project->id, 'title' => $this->project->title]),
            /** The amounts for the progress bar. */
            'totals' => [
                'total' => $this->total_cents / 100,
                /** Paid into escrow and still there. */
                'held' => $this->heldCents() / 100,
                /** Paid out to the freelancer(s), before fees. */
                'released' => $this->releasedCents() / 100,
                'refunded' => $this->refundedCents() / 100,
            ],
            'milestones' => MilestoneResource::collection($this->milestones),
            /** Who gets paid and in what share (`sharePercent`). A solo freelancer has 100. */
            'payees' => $this->payees->map(fn ($payee) => [
                'userId' => $payee->user_id,
                'fullName' => $payee->user->full_name,
                'avatarUrl' => $payee->user->avatar_url,
                'sharePercent' => $payee->share_bp / 100,
                'isLead' => $payee->is_lead,
            ])->values(),
            'createdAt' => $this->created_at,
        ];
    }
}
