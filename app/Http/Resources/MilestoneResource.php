<?php

namespace App\Http\Resources;

use App\Models\Milestone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Milestone
 */
class MilestoneResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'escrowId' => $this->escrow_id,
            'position' => $this->position,
            'title' => $this->title,
            'amount' => $this->amount_cents / 100,
            /** `pending` (waiting for the client to pay), `funded` (work under way), `submitted` (delivered), `disputed`, `released` or `refunded`. */
            'status' => $this->status,
            'dueDate' => $this->due_date?->toDateString(),
            /** The part that went back to the client (a refund, or the client's side of a settled dispute). */
            'refunded' => $this->refunded_cents / 100,
            /** The platform fee taken when it was released. */
            'fee' => $this->fee_cents / 100,
            /** What the freelancer wrote when delivering. */
            'submissionNote' => $this->submission_note,
            /** What the client asked to change after a delivery. */
            'changesNote' => $this->changes_note,
            'fundedAt' => $this->funded_at,
            'submittedAt' => $this->submitted_at,
            'releasedAt' => $this->released_at,
        ];
    }
}
