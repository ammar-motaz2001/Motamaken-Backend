<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Proposal;
use App\Support\LikeSearch;
use App\Support\Paged;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminProposalController extends Controller
{
    /**
     * Offers.
     *
     * Every offer on projects and influencer requests, with its project, sender and status. Newest first, with counts per
     * status for the tabs.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>, counts: array<string, int>}
     */
    public function index(Request $request): array
    {
        $request->validate([
            /** `pending`, `accepted`, `declined` or `withdrawn`. */
            'status' => ['nullable', Rule::in([Proposal::STATUS_PENDING, Proposal::STATUS_ACCEPTED, Proposal::STATUS_DECLINED, Proposal::STATUS_WITHDRAWN])],
            /** `project` (freelancer offers) or `influencer_request` (influencer offers). */
            'type' => ['nullable', Rule::in([Project::TYPE_PROJECT, Project::TYPE_INFLUENCER_REQUEST])],
            'projectId' => ['nullable', 'integer'],
            'userId' => ['nullable', 'integer'],
            /** Searches the offer text and the project title. */
            'q' => ['nullable', 'string', 'max:100'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->filtered($request)->with(['project.user', 'user', 'team'])->latest('proposals.id')->paginate($request->integer('perPage', 20));

        return Paged::of($page, fn (Proposal $p) => self::present($p), [
            'counts' => $this->filtered($request, false)->selectRaw('proposals.status, count(*) as total')->groupBy('proposals.status')
                ->pluck('total', 'status')->map(fn ($n) => (int) $n)->all(),
        ]);
    }

    /**
     * The offers query behind the list, shared with the CSV export; the counts per status skip the status filter.
     *
     * @return Builder<Proposal>
     */
    public function filtered(Request $request, bool $withStatus = true): Builder
    {
        return Proposal::query()
            ->when($withStatus && $request->input('status'), fn ($q) => $q->where('proposals.status', $request->input('status')))
            ->when($request->input('type'), fn ($q, $type) => $q->whereHas('project', fn ($p) => $p->where('type', $type)))
            ->when($request->input('projectId'), fn ($q, $id) => $q->where('proposals.project_id', $id))
            ->when($request->input('userId'), fn ($q, $id) => $q->where('proposals.user_id', $id))
            ->when($request->input('q'), fn ($q, $term) => $q->where(fn ($w) => LikeSearch::apply($w, ['proposals.message'], $term)
                ->orWhereHas('project', fn ($p) => LikeSearch::apply($p, ['title'], $term))));
    }

    /**
     * An offer for the admin list, with its project, sender and team.
     *
     * @return array<string, mixed>
     */
    public static function present(Proposal $p): array
    {
        return [
            'id' => $p->id,
            'project' => $p->project ? ['id' => $p->project->id, 'title' => $p->project->title, 'type' => $p->project->type, 'status' => $p->project->status, 'owner' => Paged::user($p->project->user)] : null,
            'user' => Paged::user($p->user),
            'team' => $p->team ? ['id' => $p->team->id, 'name' => $p->team->name] : null,
            'amount' => (float) $p->amount,
            'currency' => $p->currency,
            'deliveryDays' => $p->delivery_days,
            'message' => $p->message,
            'status' => $p->status,
            'isAccepted' => $p->project?->accepted_proposal_id === $p->id,
            'createdAt' => $p->created_at?->toIso8601String(),
            'updatedAt' => $p->updated_at?->toIso8601String(),
        ];
    }
}
