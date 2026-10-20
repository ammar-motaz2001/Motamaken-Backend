<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Services\RewardsService;
use App\Support\RuntimeConfig;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

#[Group('Header & account', 'The logged-in header: balance, badges, notifications, language/currency/theme, own profile.', weight: 4)]
class AvatarController extends Controller
{
    /**
     * Upload a profile photo.
     *
     * `multipart/form-data` with `avatar`: JPEG, PNG or WebP up to 5 MB, at least 100×100. Crop it in the browser
     * first; the image is stored as sent. Replaces the previous photo.
     */
    public function store(Request $request): UserResource
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:'.RuntimeConfig::uploadKb('avatar'), 'dimensions:min_width=100,min_height=100'],
        ]);

        $user = $request->user();
        $old = $user->avatar_path;
        $user->forceFill(['avatar_path' => $request->file('avatar')->store('avatars', 'public')])->save();

        if ($old) {
            Storage::disk('public')->delete($old);
        }

        app(RewardsService::class)->checkProfileCompleted($user);

        return new UserResource($user);
    }

    /**
     * Remove the profile photo.
     */
    public function destroy(Request $request): UserResource
    {
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->forceFill(['avatar_path' => null])->save();
        }

        return new UserResource($user);
    }
}
