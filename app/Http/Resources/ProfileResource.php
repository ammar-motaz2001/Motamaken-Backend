<?php

namespace App\Http\Resources;

use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The logged-in user's own profile.
 *
 * @mixin Profile
 */
class ProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** `freelancer` or `influencer`. */
            'type' => $this->type,
            'jobTitle' => $this->job_title,
            'bio' => $this->bio,
            'rating' => $this->rating,
            'reviewsCount' => $this->reviews_count,
            'countryId' => $this->country_id,
            'cityId' => $this->city_id,
            'country' => $this->whenLoaded('country', fn () => $this->country?->name),
            'city' => $this->whenLoaded('city', fn () => $this->city?->name),
            /** True while the profile is promoted in the homepage slider. */
            'isFeatured' => $this->is_featured,
            'skills' => $this->skills ?? [],
            /** `[{name, level}]`. */
            'languages' => $this->languages ?? [],
            /** `[{title, company, from, to, description}]`, newest first. */
            'experiences' => collect($this->experiences ?? [])->sortByDesc('from')->values(),
            'educations' => collect($this->educations ?? [])->sortByDesc('from')->values(),
            'certificates' => collect($this->certificates ?? [])->sortByDesc('year')->values(),
            /** Influencers: `[{platform, handle, url, followers, engagementRate}]`. */
            'channels' => $this->channels ?? [],
            'portfolio' => $this->whenLoaded('portfolio', fn () => $this->portfolio->map(fn ($item) => [
                'id' => $item->id,
                'title' => $item->title,
                'description' => $item->description,
                'imageUrl' => $item->image_url,
                'link' => $item->link,
            ])->values()),
            'createdAt' => $this->created_at,
        ];
    }
}
