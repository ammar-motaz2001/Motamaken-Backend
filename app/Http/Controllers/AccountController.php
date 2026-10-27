<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\IdentifierRequest;
use App\Http\Resources\UserResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

#[Group('Settings', 'Account settings: profile, password, notifications, two-factor authentication, login devices.', weight: 5)]
class AccountController extends Controller
{
    /**
     * Update my account.
     *
     * The "Profile" settings: name, username and newsletter. Send only what changed. Email and phone are changed
     * through their own verification and are not accepted here.
     */
    public function update(Request $request): UserResource
    {
        $user = $request->user();
        $data = $request->validate([
            'firstName' => ['sometimes', 'nullable', 'string', 'max:50', 'regex:/^[\p{L} ]+$/u'],
            'lastName' => ['sometimes', 'nullable', 'string', 'max:50', 'regex:/^[\p{L} ]+$/u'],
            'username' => ['sometimes', 'required', 'string', 'max:50', 'regex:/^[\p{L}\p{N}._]+( [\p{L}\p{N}._]+)*$/u', Rule::unique('users', 'username')->ignore($user->id)],
            /** The newsletter checkbox. */
            'subscribed' => ['sometimes', 'boolean'],
        ]);

        $map = ['firstName' => 'first_name', 'lastName' => 'last_name', 'username' => 'username', 'subscribed' => 'subscribed'];
        $user->forceFill(collect($data)->mapWithKeys(fn ($value, $key) => [$map[$key] => is_string($value) ? trim($value) : $value])->all())->save();

        return new UserResource($user);
    }

    /**
     * Change my password.
     *
     * Needs the current password. Every other device is logged out; this one stays signed in.
     *
     * @return array{success: bool}
     */
    public function password(Request $request): array
    {
        $user = $request->user();
        $request->validate([
            'currentPassword' => ['required', 'string', 'current_password:sanctum'],
            'password' => [...IdentifierRequest::passwordRules(), 'confirmed', 'different:currentPassword'],
        ]);

        $user->forceFill(['password' => Hash::make($request->input('password')), 'password_changed_at' => now()])->save();
        $user->tokens()->whereKeyNot($user->currentAccessToken()?->getKey())->delete();

        return ['success' => true];
    }

    /**
     * Notification settings.
     *
     * Which groups are also sent by email. The bell always shows everything; `account` is always emailed.
     *
     * @return array{data: array<string, bool>, required: list<string>}
     */
    public function notifications(Request $request): array
    {
        $user = $request->user();

        return [
            'data' => collect(array_keys(config('notifications.groups')))->mapWithKeys(fn ($group) => [$group => $user->wantsEmailFor($group)])->all(),
            'required' => config('notifications.required'),
        ];
    }

    /**
     * Update notification settings.
     *
     * Send the groups to change, e.g. `{"messages": true, "payments": false}`.
     *
     * @return array{data: array<string, bool>, required: list<string>}
     */
    public function updateNotifications(Request $request): array
    {
        $groups = array_diff(array_keys(config('notifications.groups')), config('notifications.required'));
        $data = $request->validate(collect($groups)->mapWithKeys(fn ($group) => [$group => ['sometimes', 'boolean']])->all());

        $user = $request->user();
        $user->forceFill(['notification_settings' => [...($user->notification_settings ?? []), ...$data]])->save();

        return $this->notifications($request);
    }
}
