<?php

namespace App\Http\Resources;

use App\Models\Dispute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Dispute
 */
class DisputeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isAdmin = (bool) $viewer?->is_admin;
        $mySide = $viewer ? $this->sideOf($viewer) : null;

        return [
            'id' => $this->id,
            /** The case number shown to everyone, e.g. `DSP-00042`. */
            'caseNumber' => $this->caseNumber(),
            /** `open`, `under_review`, `resolved` or `withdrawn`. */
            'status' => $this->status,
            'reason' => $this->reason,
            'description' => $this->description,
            'openedBy' => new UserBriefResource($this->whenLoaded('opener')),
            'against' => new UserBriefResource($this->whenLoaded('respondent')),
            /** The frozen milestone. */
            'milestone' => $this->whenLoaded('milestone', fn () => [
                'id' => $this->milestone->id,
                'title' => $this->milestone->title,
                'amount' => $this->milestone->amount_cents / 100,
                'status' => $this->milestone->status,
            ]),
            'project' => $this->whenLoaded('escrow', fn () => [
                'id' => $this->escrow->project_id,
                'title' => $this->escrow->project->title,
            ]),
            'currency' => $this->whenLoaded('escrow', fn () => $this->escrow->currency),
            /** How it ended: `client_refund`, `freelancer_payout` or `split`. Null while active. */
            'resolution' => $this->resolution,
            'clientPercent' => $this->client_percent,
            'resolutionNote' => $this->resolution_note,
            /** The admin handling the case. */
            'assignedTo' => $this->when($isAdmin, fn () => new UserBriefResource($this->whenLoaded('assignee'))),
            /** What the viewer may do now: drives the buttons on the case page. */
            'permissions' => [
                /** Write on the timeline and upload evidence. */
                'canPost' => $this->isActive() && ($mySide !== null || $isAdmin),
                /** Take the dispute back (the person who opened it). */
                'canWithdraw' => $this->isActive() && $viewer?->id === $this->opened_by,
                /** Agree with the other side and settle it without an admin. */
                'canConcede' => $this->isActive() && $mySide !== null && $mySide !== $this->sideOf($this->opener)
                    && ($mySide === 'client' || $this->escrow->payees->contains(fn ($p) => $p->user_id === $viewer->id && $p->is_lead)),
                'canResolve' => $this->isActive() && $isAdmin,
            ],
            /** The case timeline, oldest first. Only in the detail view; admin notes only for admins. */
            'timeline' => $this->whenLoaded('messages', fn () => DisputeMessageResource::collection(
                $this->messages->when(! $isAdmin, fn ($messages) => $messages->where('kind', '!=', 'admin_note'))->values(),
            )),
            'createdAt' => $this->created_at,
            'resolvedAt' => $this->resolved_at,
        ];
    }
}
