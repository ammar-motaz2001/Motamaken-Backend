<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Profile;
use App\Models\Project;
use App\Services\ProposalService;
use App\Support\LikeSearch;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminProjectController extends Controller
{
    public function __construct(private readonly ProposalService $proposals) {}

    /**
     * List projects.
     *
     * Every project in any status, with its number of offers.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::in([Project::STATUS_OPEN, Project::STATUS_IN_PROGRESS, Project::STATUS_COMPLETED, Project::STATUS_CLOSED])],
            'type' => ['nullable', Rule::in([Project::TYPE_PROJECT, Project::TYPE_INFLUENCER_REQUEST])],
            'q' => ['nullable', 'string', 'max:100'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return ProjectResource::collection(
            Project::with(['user', 'category', 'country', 'city'])->withCount('proposals')
                ->when($request->input('status'), fn ($query, $status) => $query->where('projects.status', $status))
                ->when($request->input('type'), fn ($query, $type) => $query->where('projects.type', $type))
                ->when($request->input('q'), fn ($query, $term) => LikeSearch::apply($query, ['projects.title', 'projects.description'], $term))
                ->latest('projects.created_at')->latest('projects.id')
                ->paginate($request->integer('perPage', 20)),
        );
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
