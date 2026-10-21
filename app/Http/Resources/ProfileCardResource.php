<?php

namespace App\Http\Resources;

use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A freelancer or influencer card (featured profiles slider, search results).
 *
 * @mixin Profile
 */
class ProfileCardResource extends JsonResource
{
    /**
     * Returns the short card details: name, avatar, type, job title, rating and location.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'fullName' => $this->user->full_name,
            'username' => $this->user->username,
            'avatarUrl' => $this->user->avatar_url,
            /** `freelancer` or `influencer`. */
            'type' => $this->type,
            /** E.g. "Designer", "Web Developer". */
            'jobTitle' => $this->job_title,
            /** 0 to 5, one decimal (the stars). */
            'rating' => $this->rating,
            'reviewsCount' => $this->reviews_count,
            'country' => $this->whenLoaded('country', fn () => $this->country?->name),
            'city' => $this->whenLoaded('city', fn () => $this->city?->name),
        ];
    }
}
