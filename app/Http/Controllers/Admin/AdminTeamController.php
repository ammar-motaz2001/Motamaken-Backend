<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Team;
use App\Models\TeamMember;
use App\Notifications\ActivityNotification;
use App\Services\TeamService;
use App\Support\LikeSearch;
use App\Support\Paged;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminTeamController extends Controller
{
    public function __construct(private readonly TeamService $teams) {}

    /**
     * Teams.
     *
     * Freelancer teams with their owner, member count and offers.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function index(Request $request): array
    {
        $request->validate([
            /** `draft`, `active` or `disbanded`. */
            'status' => ['nullable', Rule::in([Team::DRAFT, Team::ACTIVE, Team::DISBANDED])],
            'q' => ['nullable', 'string', 'max:100'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Team::with('owner')
            ->withCount(['members as members_count' => fn ($q) => $q->where('status', TeamMember::ACCEPTED), 'proposals'])
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->input('q'), fn ($q, $term) => LikeSearch::apply($q, ['name', 'description'], $term))
            ->latest('id')->paginate($request->integer('perPage', 20));

        return Paged::of($page, fn (Team $t) => [
            'id' => $t->id, 'name' => $t->name, 'status' => $t->status, 'owner' => Paged::user($t->owner),
            'members' => $t->members_count, 'proposals' => $t->proposals_count,
            'publishedAt' => $t->published_at?->toIso8601String(), 'createdAt' => $t->created_at?->toIso8601String(),
        ]);
    }

    /**
     * Show a team.
     *
     * Members and invitations (with their role, share and answer), offers sent as the team, its group chats (counts
     * only: the staff do not read private messages) and reports.
     *
     * @return array{data: array<string, mixed>}
     */
    public function show(int $team): array
    {
        $team = Team::with(['owner', 'members.user'])->findOrFail($team);
        $time = fn ($value) => $value?->toIso8601String();
        $memberIds = $team->members->where('status', TeamMember::ACCEPTED)->pluck('user_id');

        return ['data' => [
            'id' => $team->id, 'name' => $team->name, 'description' => $team->description, 'status' => $team->status,
            'owner' => Paged::user($team->owner), 'publishedAt' => $time($team->published_at), 'createdAt' => $time($team->created_at),
            'members' => $team->members->map(fn (TeamMember $m) => [
                'id' => $m->id, 'user' => Paged::user($m->user), 'roleTitle' => $m->role_title, 'sharePercent' => $m->share_bp / 100,
                /** `invited`, `accepted`, `declined` or `expired`. */
                'status' => $m->status, 'isLead' => (bool) $m->is_lead,
                'invitedAt' => $time($m->invited_at), 'expiresAt' => $time($m->expires_at), 'respondedAt' => $time($m->responded_at),
            ])->values()->all(),
            'proposals' => Proposal::with('project')->where('team_id', $team->id)->latest('id')->get()->map(fn (Proposal $p) => [
                'id' => $p->id, 'projectId' => $p->project_id, 'project' => $p->project?->title, 'status' => $p->status,
                'amount' => (float) $p->amount, 'currency' => $p->currency, 'createdAt' => $time($p->created_at),
            ])->all(),
            /** Group chats the members share, without their content. */
            'groupChats' => Conversation::where('type', 'group')->whereHas('participants', fn ($q) => $q->whereIn('users.id', $memberIds))
                ->withCount('messages')->latest('last_message_at')->limit(10)->get()->map(fn (Conversation $c) => [
                    'id' => $c->id, 'title' => $c->title, 'projectId' => $c->project_id, 'messages' => $c->messages_count, 'lastMessageAt' => $time($c->last_message_at),
                ])->all(),
            'reports' => Report::with('reporter')->where('target_type', 'team')->where('target_id', $team->id)->latest('id')->get()->map(fn (Report $r) => [
                'id' => $r->id, 'reason' => $r->reason, 'details' => $r->details, 'status' => $r->status, 'reporter' => Paged::user($r->reporter), 'createdAt' => $time($r->created_at),
            ])->all(),
        ]];
    }

    /**
     * Disband a team.
     *
     * For abuse: the team can no longer send offers. Work already accepted continues.
     *
     * @return array{success: bool}
     */
    public function disband(Request $request, int $team): array
    {
        $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $team = Team::with('members.user')->findOrFail($team);
        $this->teams->disband($team);

        foreach ($team->members as $member) {
            $member->user?->notify(new ActivityNotification('team.disbanded_by_admin', null, ['teamId' => $team->id, 'team' => $team->name, 'reason' => $request->input('reason')]));
        }

        return ['success' => true];
    }

    /**
     * Remove a member.
     *
     * Takes one person out of a team (abuse, a member who asked to leave and cannot).
     *
     * @return array{success: bool}
     */
    public function removeMember(int $team, int $member): array
    {
        $team = Team::findOrFail($team);
        $this->teams->removeMember($team, TeamMember::where('team_id', $team->id)->findOrFail($member));

        return ['success' => true];
    }
}
