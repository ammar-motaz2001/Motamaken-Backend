<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Requests\PaginatedRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Review;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Rules\CleanText;
use App\Services\RewardsService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

#[Group('Profiles & reviews', 'Public profiles, portfolio, reviews and content reports.', weight: 5)]
class ReviewController extends Controller
{
    /**
     * A project's reviews.
     *
     * Both sides' reviews once the project is completed, and whether the viewer can still leave theirs (`canReview`).
     *
     * @return array{data: mixed, canReview: bool}
     */
    public function project(Request $request, int $project): array
    {
        $project = Project::findOrFail($project);
        $reviews = Review::with(['reviewer', 'project'])->where('project_id', $project->id)->oldest()->get();
        $viewer = $request->user('sanctum');

        return [
            'data' => ReviewResource::collection($reviews),
            'canReview' => $viewer !== null && $this->otherSide($project, $viewer) !== null && ! $reviews->contains('reviewer_id', $viewer->id),
        ];
    }

    /**
     * Review the other side.
     *
     * After a project is completed, the client reviews the freelancer (or team lead) and the freelancer reviews the
     * client. Once per project and side.
     */
    public function store(Request $request, int $project): JsonResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:2000', new CleanText],
        ]);
        $project = Project::findOrFail($project);
        $reviewee = $this->otherSide($project, $request->user()) ?? throw new ApiException(__('profiles.review_not_allowed'), 'review_not_allowed', 403);

        if (Review::where(['project_id' => $project->id, 'reviewer_id' => $request->user()->id])->exists()) {
            throw new ApiException(__('profiles.review_exists'), 'review_exists');
        }

        $review = DB::transaction(function () use ($project, $request, $reviewee, $data) {
            $review = Review::create([
                'project_id' => $project->id, 'reviewer_id' => $request->user()->id, 'reviewee_id' => $reviewee->id,
                'rating' => $data['rating'], 'comment' => $data['comment'] ?? null,
            ]);

            Review::refreshStats($reviewee->id);

            return $review;
        });

        app(RewardsService::class)->award($request->user(), 'review_left', "review:{$review->id}", ['projectId' => $project->id]);
        app(RewardsService::class)->syncMedals($reviewee);

        $reviewee->notify(new ActivityNotification('review.received', $request->user(), [
            'projectId' => $project->id, 'project' => $project->title, 'rating' => $data['rating'],
        ]));

        return (new ReviewResource($review->load(['reviewer', 'project'])))->response()->setStatusCode(201);
    }

    /**
     * Reviews a user received.
     *
     * Newest first, with the average on the side.
     */
    public function user(PaginatedRequest $request, int $user): AnonymousResourceCollection
    {
        $user = User::findOrFail($user);
        $query = Review::where('reviewee_id', $user->id);

        return ReviewResource::collection((clone $query)->with(['reviewer', 'project'])->latest()->latest('id')->paginate($request->integer('perPage', 10)))
            ->additional(['average' => round((float) $query->avg('rating'), 1), 'total' => $query->count()]);
    }

    /**
     * Who the viewer reviews on this project, or null when they cannot review it.
     */
    private function otherSide(Project $project, User $viewer): ?User
    {
        if ($project->status !== Project::STATUS_COMPLETED || ! $project->accepted_proposal_id) {
            return null;
        }

        $freelancerId = Proposal::whereKey($project->accepted_proposal_id)->value('user_id');

        return match ($viewer->id) {
            $project->user_id => User::find($freelancerId),
            $freelancerId => User::find($project->user_id),
            default => null,
        };
    }
}
