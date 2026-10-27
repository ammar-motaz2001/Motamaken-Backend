<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProfileResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\ReviewResource;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Review;
use App\Models\User;
use App\Models\UserMedal;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Profiles & reviews', 'Public profiles, portfolio, reviews and content reports.', weight: 5)]
class PublicProfileController extends Controller
{
    /**
     * A user's public profile.
     *
     * What everyone sees: the person, their freelancer / influencer profile (null for clients), portfolio, numbers,
     * the latest reviews and, for clients, their open projects. `isMe` is true on your own profile.
     *
     * @unauthenticated
     *
     * @return array<string, mixed>
     */
    public function show(Request $request, int $user): array
    {
        $user = User::with(['profile.country', 'profile.city', 'profile.portfolio'])->findOrFail($user);
        // Staff accounts have no public profile (the panel's people are not listed), and suspended ones are hidden.
        abort_if($user->is_admin || $user->suspended_at, 404, __('errors.not_found'));
        // Activity times are for signed-in members only, not for anyone scripting the API.
        $member = $request->user('sanctum') !== null;
        abort_if($user->suspended_at ?? false, 404);

        $hired = Proposal::where('user_id', $user->id)->where('status', 'accepted')->pluck('project_id');
        $reviews = Review::where('reviewee_id', $user->id);

        return [
            'user' => [
                'id' => $user->id,
                'fullName' => $user->full_name,
                'username' => $user->username,
                'avatarUrl' => $user->avatar_url,
                'isOnline' => $member && $user->isOnline(),
                'lastSeenAt' => $member ? $user->last_seen_at : null,
                'memberSince' => $user->created_at,
                /** The trust badges. */
                'verified' => [
                    'email' => $user->email_verified_at !== null,
                    'phone' => $user->phone_verified_at !== null,
                    'identity' => $user->kyc_status === 'approved',
                ],
            ],
            'profile' => $user->profile ? new ProfileResource($user->profile) : null,
            'stats' => [
                /** As the freelancer / influencer. */
                'completedJobs' => Project::whereIn('id', $hired)->where('status', Project::STATUS_COMPLETED)->count(),
                'ongoingJobs' => Project::whereIn('id', $hired)->where('status', Project::STATUS_IN_PROGRESS)->count(),
                /** As the client. */
                'postedProjects' => Project::where('user_id', $user->id)->count(),
                'completedProjects' => Project::where('user_id', $user->id)->where('status', Project::STATUS_COMPLETED)->count(),
                'rating' => round((float) (clone $reviews)->avg('rating'), 1),
                'reviewsCount' => (clone $reviews)->count(),
            ],
            'reviews' => ReviewResource::collection((clone $reviews)->with(['reviewer', 'project'])->latest()->latest('id')->limit(3)->get()),
            'openProjects' => ProjectResource::collection(
                Project::open()->where('user_id', $user->id)->with(['user', 'category', 'country', 'city'])->latest()->limit(6)->get(),
            ),
            /** Earned medals, newest first. */
            'medals' => UserMedal::where('user_id', $user->id)->latest('awarded_at')->get()->map(fn (UserMedal $medal) => [
                'code' => $medal->code, 'name' => __("rewards.medals.{$medal->code}.name"), 'awardedAt' => $medal->awarded_at,
            ]),
            'isMe' => $request->user('sanctum')?->id === $user->id,
        ];
    }
}
