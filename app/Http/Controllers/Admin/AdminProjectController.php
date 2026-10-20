<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\AdminLog;
use App\Models\Dispute;
use App\Models\Escrow;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Review;
use App\Notifications\ActivityNotification;
use App\Services\Ai\Matcher;
use App\Services\ProposalService;
use App\Support\LikeSearch;
use App\Support\Paged;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminProjectController extends Controller
{
    public function __construct(
        private readonly ProposalService $proposals,
        private readonly Matcher $matcher,
    ) {}

    /**
     * List projects.
     *
     * Every project in any status, with its number of offers.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::in([Project::STATUS_DRAFT, Project::STATUS_OPEN, Project::STATUS_IN_PROGRESS, Project::STATUS_COMPLETED, Project::STATUS_CLOSED])],
            'type' => ['nullable', Rule::in([Project::TYPE_PROJECT, Project::TYPE_INFLUENCER_REQUEST])],
            'q' => ['nullable', 'string', 'max:100'],
            /** Projects of one owner. */
            'userId' => ['nullable', 'integer'],
            'categoryId' => ['nullable', 'integer'],
            /** `1` for projects hidden by a moderator only. */
            'hidden' => ['nullable', 'boolean'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return ProjectResource::collection(
            $this->filtered($request)->with(['user', 'category', 'country', 'city'])->withCount('proposals')
                ->latest('projects.created_at')->latest('projects.id')
                ->paginate($request->integer('perPage', 20)),
        );
    }

    /**
     * The projects query behind the list, shared with the CSV export.
     *
     * @return Builder<Project>
     */
    public function filtered(Request $request)
    {
        return Project::query()
            ->when($request->input('status'), fn ($query, $status) => $query->where('projects.status', $status))
            ->when($request->input('type'), fn ($query, $type) => $query->where('projects.type', $type))
            ->when($request->input('userId'), fn ($query, $id) => $query->where('projects.user_id', $id))
            ->when($request->input('categoryId'), fn ($query, $id) => $query->where('projects.category_id', $id))
            ->when($request->boolean('hidden'), fn ($query) => $query->whereNotNull('projects.hidden_at'))
            ->when($request->input('q'), fn ($query, $term) => LikeSearch::apply($query, ['projects.title', 'projects.description'], $term));
    }

    /**
     * Show a project.
     *
     * Everything about one project for moderation: the details, every offer, the contract (escrow, milestones, who is
     * paid), disputes, reviews, reports and the admin actions taken on it.
     *
     * @return array{data: array<string, mixed>}
     */
    public function show(int $project): array
    {
        $project = Project::with(['user', 'category', 'country', 'city'])->withCount('proposals')->findOrFail($project);
        $escrow = Escrow::with(['milestones', 'payees.user', 'client'])->where('project_id', $project->id)->first();
        $time = fn ($value) => $value?->toIso8601String();

        return ['data' => [
            ...(new ProjectResource($project))->resolve(),
            'hiddenAt' => $time($project->hidden_at),
            'hiddenReason' => $project->hidden_reason,
            'acceptedProposalId' => $project->accepted_proposal_id,
            'proposals' => Proposal::with(['user', 'team'])->where('project_id', $project->id)->latest('id')->get()->map(fn (Proposal $p) => [
                'id' => $p->id, 'user' => Paged::user($p->user), 'team' => $p->team ? ['id' => $p->team->id, 'name' => $p->team->name] : null,
                'amount' => (float) $p->amount, 'currency' => $p->currency, 'deliveryDays' => $p->delivery_days,
                'message' => $p->message, 'status' => $p->status, 'createdAt' => $time($p->created_at),
            ])->all(),
            'escrow' => $escrow ? AdminEscrowController::present($escrow, true) : null,
            'disputes' => Dispute::with(['opener', 'respondent'])->whereIn('escrow_id', $escrow ? [$escrow->id] : [])->latest('id')->get()->map(fn (Dispute $d) => [
                'id' => $d->id, 'status' => $d->status, 'reason' => $d->reason, 'resolution' => $d->resolution,
                'openedBy' => Paged::user($d->opener), 'against' => Paged::user($d->respondent), 'createdAt' => $time($d->created_at),
            ])->all(),
            'reviews' => Review::withoutGlobalScope('visible')->with(['reviewer', 'reviewee'])->where('project_id', $project->id)->get()->map(fn (Review $r) => [
                'id' => $r->id, 'rating' => $r->rating, 'comment' => $r->comment, 'hidden' => $r->hidden_at !== null,
                'reviewer' => Paged::user($r->reviewer), 'reviewee' => Paged::user($r->reviewee), 'createdAt' => $time($r->created_at),
            ])->all(),
            'reports' => Report::with('reporter')->where('target_type', 'project')->where('target_id', $project->id)->latest('id')->get()->map(fn (Report $r) => [
                'id' => $r->id, 'reason' => $r->reason, 'details' => $r->details, 'status' => $r->status, 'source' => $r->source,
                'reporter' => Paged::user($r->reporter), 'createdAt' => $time($r->created_at),
            ])->all(),
            'adminLog' => AdminLog::with('admin')->where('subject_type', 'projects')->where('subject_id', $project->id)->latest('id')->limit(30)->get()->map(fn (AdminLog $l) => [
                'id' => $l->id, 'action' => $l->action, 'changes' => $l->changes, 'admin' => Paged::user($l->admin), 'createdAt' => $time($l->created_at),
            ])->all(),
            /** Freelancers or influencers whose profiles are closest in meaning (needs the AI matching to be on). */
            'matches' => $this->matcher->forProject($project),
        ]];
    }

    /**
     * Hide a project.
     *
     * Takes a project off the site without changing its status or touching its contract: only the owner and the staff
     * can still open it, with the reason shown to the owner.
     */
    public function hide(Request $request, int $project): ProjectResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $project = Project::findOrFail($project);
        $project->update(['hidden_at' => now(), 'hidden_reason' => $data['reason'], 'is_featured' => false, 'featured_until' => null]);
        $project->user?->notify(new ActivityNotification('project.hidden', null, ['projectId' => $project->id, 'project' => $project->title, 'reason' => $data['reason']]));

        return new ProjectResource($project->load(['user', 'category', 'country', 'city']));
    }

    /**
     * Reactivate a project.
     *
     * Shows a hidden project again. A project closed by mistake that never had an accepted offer is opened again too.
     */
    public function reactivate(int $project): ProjectResource
    {
        $project = Project::findOrFail($project);
        $changes = ['hidden_at' => null, 'hidden_reason' => null];

        if ($project->status === Project::STATUS_CLOSED && ! $project->accepted_proposal_id) {
            $changes['status'] = Project::STATUS_OPEN;
        }

        $project->update($changes);
        $project->user?->notify(new ActivityNotification('project.reactivated', null, ['projectId' => $project->id, 'project' => $project->title]));

        return new ProjectResource($project->load(['user', 'category', 'country', 'city']));
    }

    /**
     * Close a project.
     *
     * Takes an open project off the site (spam, rule breaking). Pending offers are declined and their senders told.
     */
    public function close(int $project): ProjectResource
    {
        return new ProjectResource($this->proposals->close(Project::findOrFail($project))->load(['user', 'category', 'country', 'city']));
    }

    /**
     * Feature a project.
     *
     * Puts an open project in the homepage "Distinctive projects" slider for some days.
     */
    public function feature(Request $request, int $project): ProjectResource
    {
        $data = $request->validate([
            /** How long it stays featured. */
            'days' => ['required', 'integer', 'min:1', 'max:90'],
        ]);

        $project = Project::findOrFail($project);
        $project->update(['is_featured' => true, 'featured_until' => now()->addDays($data['days'])]);

        return new ProjectResource($project->load(['user', 'category', 'country', 'city']));
    }

    /**
     * Stop featuring a project.
     */
    public function unfeature(int $project): ProjectResource
    {
        $project = Project::findOrFail($project);
        $project->update(['is_featured' => false, 'featured_until' => null]);

        return new ProjectResource($project->load(['user', 'category', 'country', 'city']));
    }

    /**
     * Feature a profile.
     *
     * Puts a profile in the homepage "Featured profiles" slider for some days; send `days` 0 to remove it.
     *
     * @return array{id: int, isFeatured: bool, featuredUntil: ?string}
     */
    public function featureProfile(Request $request, int $profile): array
    {
        $data = $request->validate(['days' => ['required', 'integer', 'min:0', 'max:90']]);

        $profile = Profile::findOrFail($profile);
        $profile->update($data['days'] > 0
            ? ['is_featured' => true, 'featured_until' => now()->addDays($data['days'])]
            : ['is_featured' => false, 'featured_until' => null]);

        return ['id' => $profile->id, 'isFeatured' => $profile->is_featured, 'featuredUntil' => $profile->featured_until?->toIso8601String()];
    }
}
