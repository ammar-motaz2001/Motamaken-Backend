<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePreferencesRequest;
use App\Http\Resources\UserResource;
use App\Models\Account;
use App\Models\Favorite;
use App\Models\Message;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Header & account', 'The logged-in header: balance, badges, notifications, language/currency/theme, own profile.', weight: 4)]
class MeController extends Controller
{
    /**
     * Header summary.
     *
     * Everything the logged-in header shows: the name, the wallet balance pill, the points pill
     * and the red badges on the messages and bell icons. Cheap enough to poll every minute or so.
     */
    public function summary(Request $request): array
    {
        $user = $request->user();

        return [
            'user' => new UserResource($user),
            /** The wallet pill: available money in the user's preferred currency. */
            'wallet' => [
                'balance' => $this->wallet($user)->balance_cents / 100,
                'currency' => $user->currency,
            ],
            'points' => $user->points,
            /** Messages received and not read yet, across all conversations. */
            'unreadMessages' => Message::unreadFor($user)->count(),
            'unreadNotifications' => $user->unreadNotifications()->count(),
            /** Counts beside the header and account-menu links. */
            'counts' => [
                /** Saved projects and profiles. */
                'favorites' => Favorite::where('user_id', $user->id)->count(),
                /** My projects that are open or in progress. */
                'projects' => Project::where('user_id', $user->id)->whereIn('status', [Project::STATUS_OPEN, Project::STATUS_IN_PROGRESS])->count(),
                /** Offers waiting for my answer on my open projects. */
                'offersReceived' => Proposal::where('status', Proposal::STATUS_PENDING)
                    ->whereIn('project_id', Project::where('user_id', $user->id)->where('status', Project::STATUS_OPEN)->select('id'))->count(),
                /** My offers still waiting for an answer. */
                'offers' => Proposal::where('user_id', $user->id)->where('status', Proposal::STATUS_PENDING)->count(),
            ],
        ];
    }

    /**
     * Update preferences.
     *
     * Saves the header choices: language, currency and light/dark mode. Send only what changed.
     */
    public function updatePreferences(UpdatePreferencesRequest $request): UserResource
    {
        $request->user()->update($request->validated());

        return new UserResource($request->user()->refresh());
    }

    /**
     * The user's wallet in their own currency, or an empty one when they have never had money.
     */
    private function wallet(User $user): Account
    {
        return Account::firstWhere(['type' => 'wallet', 'user_id' => $user->id, 'currency' => $user->currency])
            ?? new Account(['balance_cents' => 0]);
    }
}
