<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProposalListRequest;
use App\Http\Requests\ProposalRequest;
use App\Http\Resources\ProposalResource;
use App\Models\Project;
use App\Models\Proposal;
use App\Services\ProposalService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

#[Group('Projects & proposals', 'Post projects and influencer requests, send and answer offers, "My Projects" and "My Offers".', weight: 3)]
class ProposalController extends Controller
{
    public function __construct(private readonly ProposalService $proposals) {}

    /**
     * Offers on my project.
     *
     * Owner only. The offers a project received, newest first, each with the sender's name, rating and job title.
     */
    public function index(ProposalListRequest $request, int $project): AnonymousResourceCollection
    {
        $project = Project::findOrFail($project);
        Gate::authorize('manage', $project);

        return ProposalResource::collection(
            $project->proposals()
                ->with(['user.profile', 'team.members.user'])
                ->when($request->input('status'), fn ($query, $status) => $query->where('status', $status))
                ->latest()
                ->latest('id')
                ->paginate($request->integer('perPage', 15)),
        );
    }

    /**
     * Send an offer.
     *
     * "Add Your Offer". Needs a profile of the right kind (freelancer for projects, influencer for influencer requests),
     * an open project that is not your own, and one offer per project. The owner is notified.
     *
     * Refusals answer `{message, code}` with one of: `profile_required`, `profile_type_mismatch`, `own_project`,
     * `already_offered`, `project_not_open`.
     */
    public function store(ProposalRequest $request, int $project): JsonResponse
    {
        $project = Project::with('user')->findOrFail($project);
        $proposal = $this->proposals->submit($project, $request->user(), $request->validated());

        return (new ProposalResource($proposal->load(['user.profile', 'team.members.user'])))->response()->setStatusCode(201);
    }

    /**
     * Show an offer.
     *
     * Visible to the person who made it and to the owner of the project.
     */
    public function show(Request $request, int $proposal): ProposalResource
    {
        $proposal = Proposal::with(['user.profile', 'team.members.user', 'project.user', 'project.category'])->findOrFail($proposal);
        Gate::authorize('view', $proposal);

        return new ProposalResource($proposal);
    }

    /**
     * Edit my offer.
     *
     * Only while nobody has answered it and the project is open. Send only the fields that change.
     */
    public function update(ProposalRequest $request, int $proposal): ProposalResource
    {
        $proposal = Proposal::with('project')->findOrFail($proposal);
        Gate::authorize('change', $proposal);

        return new ProposalResource($this->proposals->update($proposal, $request->validated())->load(['user.profile', 'team.members.user']));
    }

    /**
     * Withdraw my offer.
     *
     * Only while it is pending.
     */
    public function withdraw(Request $request, int $proposal): ProposalResource
    {
        $proposal = Proposal::findOrFail($proposal);
        Gate::authorize('change', $proposal);

        return new ProposalResource($this->proposals->withdraw($proposal));
    }

    /**
     * Accept an offer.
     *
     * Owner only. The project goes `in_progress`, every other pending offer is declined, and the senders are notified.
     */
    public function accept(Request $request, int $proposal): ProposalResource
    {
        $proposal = Proposal::with(['project.user', 'user'])->findOrFail($proposal);
        Gate::authorize('answer', $proposal);

        return new ProposalResource($this->proposals->accept($proposal)->load(['user.profile', 'team.members.user']));
    }

    /**
     * Decline an offer.
     *
     * Owner only, while it is pending. The sender is notified.
     */
    public function decline(Request $request, int $proposal): ProposalResource
    {
        $proposal = Proposal::with(['project.user', 'user'])->findOrFail($proposal);
        Gate::authorize('answer', $proposal);

        return new ProposalResource($this->proposals->decline($proposal)->load(['user.profile', 'team.members.user']));
    }

    /**
     * My offers.
     *
     * "My Offers" in the user menu: every offer the user sent, newest first, with the project it is on.
     */
    public function mine(ProposalListRequest $request): AnonymousResourceCollection
    {
        return ProposalResource::collection(
            Proposal::query()
                ->where('user_id', $request->user()->id)
                ->when($request->input('status'), fn ($query, $status) => $query->where('status', $status))
                ->with(['project.user', 'project.category', 'project.country', 'project.city'])
                ->latest()
                ->latest('id')
                ->paginate($request->integer('perPage', 15)),
        );
    }
}
