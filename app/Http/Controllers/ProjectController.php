<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectIndexRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Homepage', 'Public data for the homepage, search bar and category menus. No login needed.', weight: 2)]
class ProjectController extends Controller
{
    /**
     * List projects.
     *
     * Open projects and influencer requests, newest first by default. Use it for the homepage category tabs,
     * the platform tabs and the "Show All" pages.
     *
     * @unauthenticated
     */
    public function index(ProjectIndexRequest $request): AnonymousResourceCollection
    {
        $projects = Project::open()
            ->with(['user', 'category', 'country', 'city'])
            ->filter($request->validated())
            ->when($request->input('sort'), fn ($query, $sort) => match ($sort) {
                'budget_high' => $query->orderByRaw(Project::inUsd('projects.budget_max').' desc'),
                'budget_low' => $query->orderByRaw(Project::inUsd('projects.budget_min').' asc'),
                default => $query,
            })
            ->latest('projects.created_at')
            ->latest('projects.id')
            ->paginate($request->integer('perPage', 15));

        return ProjectResource::collection($projects);
    }

    /**
     * Show a project.
     *
     * Open projects are public. Other statuses are visible only to the owner and to people who sent an offer.
     * With a token, `myProposal` holds the visitor's own offer (or null) so the page can show "Edit your offer".
     *
     * @unauthenticated
     */
    public function show(Request $request, int $project): ProjectResource
    {
        $viewer = $request->user('sanctum');
        $project = Project::with(['user', 'category', 'country', 'city'])->findOrFail($project);
        $offer = $viewer ? $project->proposals()->where('user_id', $viewer->id)->first() : null;

        $isOwner = $viewer?->id === $project->user_id;
        // Hidden by a moderator or still a draft: only the owner (and the staff) can open it.
        abort_if(($project->hidden_at || $project->status === Project::STATUS_DRAFT) && ! $isOwner && ! $viewer?->is_admin, 404);
        abort_unless($project->isOpen() || $isOwner || $offer || $viewer?->is_admin, 404);

        if ($viewer && $viewer->id !== $project->user_id) {
            $project->setRelation('viewerProposal', $offer);
        }

        return new ProjectResource($project);
    }
}
