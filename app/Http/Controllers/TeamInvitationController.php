<?php

namespace App\Http\Controllers;

use App\Http\Resources\TeamInvitationResource;
use App\Http\Resources\TeamResource;
use App\Models\TeamMember;
use App\Services\TeamService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Teams', 'Freelancers who take projects together and split the pay by agreed shares.', weight: 10)]
class TeamInvitationController extends Controller
{
    public function __construct(private readonly TeamService $teams) {}

    /**
     * My invitations.
     *
     * Invitations to join a team that you can still answer, soonest to expire first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return TeamInvitationResource::collection(
            TeamMember::with('team.owner')
                ->where('user_id', $request->user()->id)->where('status', TeamMember::INVITED)->where('expires_at', '>', now())
                ->orderBy('expires_at')->get(),
        );
    }

    /**
     * Accept an invitation.
     *
     * Joins the team and approves your share. After the invitation expired: `invitation_expired`.
     */
    public function accept(Request $request, int $member): TeamResource
    {
        $member = $this->mine($request, $member);

        return new TeamResource($this->teams->accept($member)->team);
    }

    /**
     * Decline an invitation.
     */
    public function decline(Request $request, int $member): TeamResource
    {
        $member = $this->mine($request, $member);

        return new TeamResource($this->teams->decline($member)->team);
    }

    private function mine(Request $request, int $id): TeamMember
    {
        return TeamMember::with(['team.owner', 'user'])->where('user_id', $request->user()->id)->findOrFail($id);
    }
}
