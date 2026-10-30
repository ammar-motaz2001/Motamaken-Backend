<?php

namespace App\Http\Controllers;

use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProfileCardResource;
use App\Http\Resources\ProjectResource;
use App\Models\Category;
use App\Models\Profile;
use App\Models\Project;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Support\Facades\Cache;

#[Group('Homepage', 'Public data for the homepage, search bar and category menus. No login needed.', weight: 2)]
class HomeController extends Controller
{
    /**
     * Homepage.
     *
     * Everything the homepage needs in one request, for visitors and logged-in users alike:
     * the featured profiles and distinctive projects sliders (6 cards each, 4 shown at a time),
     * and the first tab of "Most recent projects" and "Latest requests from influencers".
     * Other tabs load with `GET /projects?category=...`. Cached for a minute.
     *
     * @unauthenticated
     */
    public function index(): array
    {
        // Cached as plain arrays: the cache store refuses to unserialize objects (config cache.serializable_classes).
        return Cache::remember(
            'home:'.app()->getLocale(),
            config('marketplace.home.cache_seconds'),
            fn () => json_decode(json_encode($this->present($this->load())), true),
        );
    }

    /**
     * Turns the cached home page models into API resources.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function present(array $data): array
    {
        return [
            'featuredProfiles' => ProfileCardResource::collection($data['featuredProfiles']),
            'distinctiveProjects' => ProjectResource::collection($data['distinctiveProjects']),
            'projectCategories' => CategoryResource::collection($data['projectCategories']),
            /** The default tab of "Most recent projects": the first category. */
            'recentProjects' => ProjectResource::collection($data['recentProjects']),
            'influencerPlatforms' => CategoryResource::collection($data['influencerPlatforms']),
            /** The default tab of "Latest requests from influencers": the first platform. */
            'latestInfluencerRequests' => ProjectResource::collection($data['latestInfluencerRequests']),
        ];
    }

    /**
     * Reads everything the home page shows: featured profiles and projects, categories and the first tab of each list.
     *
     * @return array<string, mixed>
     */
    private function load(): array
    {
        $featuredLimit = config('marketplace.home.featured_limit');
        $tabLimit = config('marketplace.home.tab_limit');
        $card = ['user', 'category', 'country', 'city'];

        $projectCategories = Category::ofType(Category::TYPE_PROJECT)->where('is_active', true)->whereNull('parent_id')->orderBy('sort_order')->get();
        $influencerPlatforms = Category::ofType(Category::TYPE_INFLUENCER)->where('is_active', true)->whereNull('parent_id')->orderBy('sort_order')->get();

        $latestIn = fn (string $type, ?Category $category) => $category
            ? Project::open()->with($card)->filter(['type' => $type, 'category' => $category->slug])->latest()->limit($tabLimit)->get()
            : collect();

        // Paid placements first; the rest of the slider is filled with the best-rated profiles and newest open projects,
        // so the sections are never empty on a young marketplace.
        $profiles = Profile::featured()
            // Staff and suspended accounts have no public profile: their card would open a 404.
            ->whereHas('user', fn ($q) => $q->where('is_admin', false)->whereNull('suspended_at'))
            ->with(['user', 'country', 'city'])->orderByDesc('rating')->orderBy('id')->limit($featuredLimit)->get();
        if ($profiles->count() < $featuredLimit) {
            $profiles = $profiles->concat(Profile::query()->with(['user', 'country', 'city'])->whereNotIn('id', $profiles->pluck('id'))
                ->whereHas('user', fn ($q) => $q->where('is_admin', false)->whereNull('suspended_at'))
                ->orderByDesc('rating')->orderByDesc('reviews_count')->latest('id')->limit($featuredLimit - $profiles->count())->get());
        }
        $projects = Project::open()->distinctive()->with($card)->filter(['type' => Project::TYPE_PROJECT])->latest()->limit($featuredLimit)->get();
        if ($projects->count() < $featuredLimit) {
            $projects = $projects->concat(Project::open()->with($card)->filter(['type' => Project::TYPE_PROJECT])->whereNotIn('projects.id', $projects->pluck('id'))
                ->latest()->limit($featuredLimit - $projects->count())->get());
        }

        return [
            'featuredProfiles' => $profiles,
            'distinctiveProjects' => $projects,
            'projectCategories' => $projectCategories,
            'recentProjects' => $latestIn(Project::TYPE_PROJECT, $projectCategories->first()),
            'influencerPlatforms' => $influencerPlatforms,
            'latestInfluencerRequests' => $latestIn(Project::TYPE_INFLUENCER_REQUEST, $influencerPlatforms->first()),
        ];
    }
}
