<?php

namespace App\Http\Resources;

use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Team
 */
class TeamResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->loadMissing(['owner', 'members.user.profile']);
        $blockers = $this->status === Team::DRAFT ? $this->blockers($this->members) : [];

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            /** `draft` (being put together), `active` (published: can send offers) or `disbanded`. */
            'status' => $this->status,
            'owner' => new UserBriefResource($this->owner),
            'members' => $this->members->map(fn ($member) => [
                'id' => $member->id,
                'user' => [
                    'id' => $member->user->id,
                    'fullName' => $member->user->full_name,
                    'avatarUrl' => $member->user->avatar_url,
                    'jobTitle' => $member->user->profile?->job_title,
                    'rating' => $member->user->profile?->rating,
                ],
                'roleTitle' => $member->role_title,
                /** Their cut of every payment. All shares add up to 100. */
                'sharePercent' => $member->share_bp / 100,
                /** `invited` (waiting), `accepted`, `declined` or `expired`. */
                'status' => $member->status,
                'isLead' => $member->is_lead,
                /** When an unanswered invitation runs out. */
                'expiresAt' => $member->status === 'invited' ? $member->expires_at : null,
                'respondedAt' => $member->responded_at,
            ])->values(),
            /** The publish gate: `ready` is true when nothing blocks publishing. */
            'readiness' => [
                'ready' => $this->status === Team::DRAFT && $blockers === [],
                /** What still blocks it: `too_few_members`, `awaiting_acceptances`, `invitations_not_accepted`, `lead_share_too_low`. */
                'blockers' => $blockers,
            ],
            /** The viewer's own place in the team, to show the accept/decline buttons. */
            'myMembership' => $request->user() ? $this->members->where('user_id', $request->user()->id)->map(fn ($m) => [
                'id' => $m->id, 'status' => $m->status, 'isLead' => $m->is_lead,
            ])->first() : null,
            'publishedAt' => $this->published_at,
            'createdAt' => $this->created_at,
        ];
    }
}
