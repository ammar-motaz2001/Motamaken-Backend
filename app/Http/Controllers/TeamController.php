<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaginatedRequest;
use App\Http\Requests\TeamMemberRequest;
use App\Http\Requests\TeamRequest;
use App\Http\Resources\TeamResource;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\TeamService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

#[Group('Teams', 'Freelancers who take projects together and split the pay by agreed shares.', weight: 10)]
class TeamController extends Controller
{
    public function __construct(private readonly TeamService $teams) {}

    /**
     * Create a team.
     *
     * You become the lead and start with 100%. Invite members, set their shares and publish it. Needs a freelancer profile.
     */
    public function store(TeamRequest $request): JsonResponse
    {
        abort_unless($request->user()->profile()->where('type', 'freelancer')->exists(), 422, __('teams.member_needs_profile'));

        $team = $this->teams->create($request->user(), $request->input('name'), $request->input('description'));

        return (new TeamResource($team))->response()->setStatusCode(201);
    }

    /**
     * My teams.
     *
     * Teams you lead or belong to (invitations you have not answered are in `GET /me/team-invitations`).
     */
    public function index(PaginatedRequest $request): AnonymousResourceCollection
    {
        $me = $request->user()->id;

        return TeamResource::collection(
            Team::with(['owner', 'members.user.profile'])
                ->where('status', '!=', Team::DISBANDED)
                ->where(fn ($query) => $query->where('owner_id', $me)->orWhereHas('members', fn ($q) => $q->where('user_id', $me)->where('status', TeamMember::ACCEPTED)))
                ->latest()->latest('id')
                ->paginate($request->integer('perPage', 15)),
        );
    }

    /**
     * Show a team.
     *
     * Members, shares, who has answered, and `readiness` for the publish checklist. Visible to the lead and to invited people.
     */
    public function show(Request $request, int $team): TeamResource
    {
        $team = Team::findOrFail($team);
        Gate::authorize('view', $team);

        return new TeamResource($team);
    }

    /**
     * Rename a team.
     */
    public function update(TeamRequest $request, int $team): TeamResource
    {
        $team = Team::findOrFail($team);
        Gate::authorize('lead', $team);
        $team->update($request->validated());

        return new TeamResource($team);
    }

    /**
     * Invite a member.
     *
     * Lead only, while the team is a draft. The invitation lasts 7 days; the person approves the share by accepting.
     * The lead's own share becomes whatever the others leave. Only freelancers with a profile can be invited.
     */
    public function invite(TeamMemberRequest $request, int $team): JsonResponse
    {
        $team = $this->ledBy($team, $request);
        $this->teams->invite($team, User::findOrFail($request->integer('userId')), $request->input('roleTitle'), $request->shareBp());

        return (new TeamResource($team->refresh()))->response()->setStatusCode(201);
    }

    /**
     * Change a member's role or share.
     *
     * Lead only, in a draft. A new share has to be accepted again.
     */
    public function updateMember(TeamMemberRequest $request, int $team, int $member): TeamResource
    {
        $team = $this->ledBy($team, $request);
        $this->teams->updateMember($team, $team->members()->findOrFail($member), $request->input('roleTitle'), $request->shareBp());

        return new TeamResource($team->refresh());
    }

    /**
     * Remove a member or cancel an invitation.
     *
     * Lead only, in a draft. The lead gets the freed share.
     */
    public function removeMember(Request $request, int $team, int $member): TeamResource
    {
        $team = $this->ledBy($team, $request);
        $this->teams->removeMember($team, $team->members()->findOrFail($member));

        return new TeamResource($team->refresh());
    }

    /**
     * Send an invitation again.
     *
     * For invitations that expired or were declined: the 7 days start over.
     */
    public function resend(Request $request, int $team, int $member): TeamResource
    {
        $team = $this->ledBy($team, $request);
        $this->teams->resend($team, $team->members()->findOrFail($member));

        return new TeamResource($team->refresh());
    }

    /**
     * Publish the team.
     *
     * Lead only. The publish gate: at least two members and every invitation accepted (`team_not_ready` with the list
     * of `blockers` otherwise). A published team can send offers on projects.
     */
    public function publish(Request $request, int $team): TeamResource
    {
        return new TeamResource($this->teams->publish($this->ledBy($team, $request)));
    }

    /**
     * Take the team back to a draft.
     *
     * Lead only, to change members or shares. Not while the team is working on a project (`team_in_use`).
     */
    public function unpublish(Request $request, int $team): TeamResource
    {
        return new TeamResource($this->teams->unpublish($this->ledBy($team, $request)));
    }

    /**
     * Disband the team.
     *
     * Lead only. Not while the team is working on a project.
     */
    public function disband(Request $request, int $team): TeamResource
    {
        return new TeamResource($this->teams->disband($this->ledBy($team, $request)));
    }

    /**
     * Leave a team.
     *
     * Any member except the lead. A published team goes back to a draft and the lead is told. Not while the team works on a project.
     *
     * @return array{success: bool}
     */
    public function leave(Request $request, int $team): array
    {
        $team = Team::findOrFail($team);
        $member = $team->members()->where('user_id', $request->user()->id)->where('status', TeamMember::ACCEPTED)->firstOrFail();
        $member->setRelation('user', $request->user());

        $this->teams->leave($team->setRelation('owner', $team->owner), $member);

        return ['success' => true];
    }

    /**
     * Loads a team and checks the current user is its lead.
     */
    private function ledBy(int $id, Request $request): Team
    {
        $team = Team::findOrFail($id);
        Gate::authorize('lead', $team);

        return $team;
    }
}
