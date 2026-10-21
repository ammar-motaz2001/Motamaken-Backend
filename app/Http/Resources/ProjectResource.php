<?php

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * A project or influencer-request card.
 *
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * Returns the project card with budget, status, offer count, my offer, category, owner and location.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** `project` or `influencer_request`. */
            'type' => $this->type,
            'title' => $this->title,
            'description' => $this->description,
            /** First 100 characters, for the "read more" card. */
            'excerpt' => Str::limit($this->description, 100),
            'budget' => [
                'min' => $this->budget_min,
                'max' => $this->budget_max,
                'currency' => $this->currency,
            ],
            /** `draft`, `open`, `in_progress`, `completed` or `closed`. Only open projects are public. */
            'status' => $this->status,
            /** In the homepage "Distinctive projects" slider. */
            'isFeatured' => (bool) $this->is_featured,
            /** Taken off the site by a moderator: only the owner and the staff can open it. */
            'isHidden' => $this->hidden_at !== null,
            /** Why it was hidden, shown to the owner. */
            'hiddenReason' => $this->when($this->hidden_at !== null, fn () => $this->hidden_reason),
            /** How many offers it received. Present in "My Projects". */
            'proposalsCount' => $this->whenCounted('proposals'),
            /** The accepted offer, once the owner hired someone. */
            'acceptedProposalId' => $this->accepted_proposal_id,
            /** The logged-in visitor's own offer on this project, if any (project page: show "Edit your offer"). */
            'myProposal' => $this->whenLoaded('viewerProposal', fn () => $this->viewerProposal ? new ProposalResource($this->viewerProposal) : null),
            'category' => new CategoryResource($this->whenLoaded('category')),
            'owner' => new UserBriefResource($this->whenLoaded('user')),
            'country' => $this->whenLoaded('country', fn () => $this->country?->name),
            'city' => $this->whenLoaded('city', fn () => $this->city?->name),
            /** The frontend renders "1 hour ago" from this. */
            'createdAt' => $this->created_at,
        ];
    }
}
