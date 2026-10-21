<?php

namespace App\Http\Resources;

use App\Models\TeamMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An invitation to join a team, as the invited person sees it.
 *
 * @mixin TeamMember
 */
class TeamInvitationResource extends JsonResource
{
    /**
     * Returns the team, who invited me, the offered role and share, and when it expires.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'team' => ['id' => $this->team->id, 'name' => $this->team->name, 'description' => $this->team->description],
            'invitedBy' => new UserBriefResource($this->team->owner),
            'roleTitle' => $this->role_title,
            /** The cut you would get of every payment. Accepting means you agree to it. */
            'sharePercent' => $this->share_bp / 100,
            'status' => $this->status,
            'invitedAt' => $this->invited_at,
            'expiresAt' => $this->expires_at,
        ];
    }
}
