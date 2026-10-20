<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Requests\MyProjectsRequest;
use App\Http\Requests\ProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\ProposalService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

#[Group('Projects & proposals', 'Post projects and influencer requests, send and answer offers, "My Projects" and "My Offers".', weight: 3)]
class ProjectManagementController extends Controller
{
    private const WITH = ['user', 'category', 'country', 'city'];

    public function __construct(private readonly ProposalService $proposals) {}

    /**
     * Post a project.
     *
     * "Post Your Project": a project for freelancers, or a request for influencers. It is public right away, unless sent
     * with `draft: true`: then it is saved for the owner only until published.
     */
    public function store(ProjectRequest $request): JsonResponse
    {
        $project = Project::create([
            ...$this->attributes($request),
            'user_id' => $request->user()->id,
            'type' => $request->input('type'),
            'status' => $request->boolean('draft') ? Project::STATUS_DRAFT : Project::STATUS_OPEN,
        ]);

        return (new ProjectResource($project->load(self::WITH)))->response()->setStatusCode(201);
    }

    /**
     * Edit a project.
     *
     * Owner only, while the project is a draft or open. Send only the fields that change.
     */
    public function update(ProjectRequest $request, int $project): ProjectResource
    {
        $project = Project::findOrFail($project);
        Gate::authorize('manage', $project);

        if (! $project->isEditable()) {
            throw new ApiException(__('proposals.project_not_editable'), 'project_not_editable');
        }

        $project->update($this->attributes($request));

        return new ProjectResource($project->load(self::WITH));
    }

    /**
     * Delete a project.
     *
     * Owner only, and only while it has no offers. Otherwise close it.
     *
     * @return array{success: bool}
     */
    public function destroy(Request $request, int $project): array
    {
        $project = Project::findOrFail($project);
        Gate::authorize('manage', $project);

        if (! $project->isEditable()) {
            throw new ApiException(__('proposals.project_not_editable'), 'project_not_editable');
        }

        if ($project->proposals()->exists()) {
            throw new ApiException(__('proposals.project_has_proposals'), 'project_has_proposals');
        }

        $project->delete();

        return ['success' => true];
    }

    /**
     * Publish a draft.
     *
     * Owner only: the draft goes public and starts receiving offers.
     */
    public function publish(Request $request, int $project): ProjectResource
    {
        $project = Project::findOrFail($project);
        Gate::authorize('manage', $project);

        if ($project->status !== Project::STATUS_DRAFT) {
            throw new ApiException(__('proposals.not_a_draft'), 'not_a_draft');
        }

        $project->update(['status' => Project::STATUS_OPEN, 'created_at' => now()]);

        return new ProjectResource($project->load(self::WITH));
    }

    /**
     * Close a project.
     *
     * Owner only, while it is open. Takes it off the site; pending offers are declined and their senders notified.
     */
    public function close(Request $request, int $project): ProjectResource
    {
        $project = Project::findOrFail($project);
        Gate::authorize('manage', $project);

        return new ProjectResource($this->proposals->close($project)->load(self::WITH));
    }

    /**
     * Mark a project as completed.
     *
     * Owner only, once the work is done (the project is `in_progress`).
     */
    public function complete(Request $request, int $project): ProjectResource
    {
        $project = Project::findOrFail($project);
        Gate::authorize('manage', $project);

        return new ProjectResource($this->proposals->complete($project)->load(self::WITH));
    }

    /**
     * My projects.
     *
     * "My Projects" in the user menu: everything the user posted, newest first, with the number of offers each received.
     */
    public function mine(MyProjectsRequest $request): AnonymousResourceCollection
    {
        $projects = Project::query()
            ->where('user_id', $request->user()->id)
            ->when($request->input('status'), fn ($query, $status) => $query->where('status', $status))
            ->with(self::WITH)
            ->withCount('proposals')
            ->latest()
            ->latest('id')
            ->paginate($request->integer('perPage', 15));

        return ProjectResource::collection($projects);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(ProjectRequest $request): array
    {
        $map = [
            'categoryId' => 'category_id', 'title' => 'title', 'description' => 'description',
            'budgetMin' => 'budget_min', 'budgetMax' => 'budget_max', 'currency' => 'currency',
            'countryId' => 'country_id', 'cityId' => 'city_id',
        ];

        $attributes = [];

        foreach ($map as $input => $column) {
            if ($request->has($input)) {
                $attributes[$column] = $request->input($input);
            }
        }

        // Changing the country without choosing a city clears the old city.
        if ($request->has('countryId') && ! $request->has('cityId')) {
            $attributes['city_id'] = null;
        }

        return $request->isMethod('POST') ? $attributes + ['currency' => 'USD'] : $attributes;
    }
}
