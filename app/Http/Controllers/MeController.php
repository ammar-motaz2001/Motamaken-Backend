<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePreferencesRequest;
use App\Http\Resources\UserResource;
use App\Models\Account;
use App\Models\Message;
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
