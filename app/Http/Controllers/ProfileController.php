<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileRequest;
use App\Http\Resources\ProfileResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Header & account', 'The logged-in header: balance, badges, notifications, language/currency/theme, own profile.', weight: 4)]
class ProfileController extends Controller
{
    /**
     * My profile.
     *
     * 404 when the user has no profile yet: show the "Add Your Profile" card.
     */
    public function show(Request $request): ProfileResource
    {
        return new ProfileResource($request->user()->profile()->with(['country', 'city'])->firstOrFail());
    }

    /**
     * Save my profile.
     *
     * "Add Your Profile": creates the profile, or replaces it when it exists. A profile is needed to send offers.
     * Being featured on the homepage is not something users can set.
     */
    public function update(ProfileRequest $request): JsonResponse
    {
        $profile = $request->user()->profile()->firstOrNew();
        $created = ! $profile->exists;

        $profile->fill([
            'type' => $request->input('type'),
            'job_title' => $request->input('jobTitle'),
            'bio' => $request->input('bio'),
            'country_id' => $request->input('countryId'),
            'city_id' => $request->input('cityId'),
        ])->save();

        return (new ProfileResource($profile->load(['country', 'city'])))->response()->setStatusCode($created ? 201 : 200);
    }
}
