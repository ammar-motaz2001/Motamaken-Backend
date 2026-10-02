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
        $data = Cache::remember('home:'.app()->getLocale(), config('marketplace.home.cache_seconds'), fn () => $this->load());

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
     * @return array<string, mixed>
     */
    private function load(): array
    {
        $featuredLimit = config('marketplace.home.featured_limit');
        $tabLimit = config('marketplace.home.tab_limit');
        $card = ['user', 'category', 'country', 'city'];

        $projectCategories = Category::ofType(Category::TYPE_PROJECT)->orderBy('sort_order')->get();
        $influencerPlatforms = Category::ofType(Category::TYPE_INFLUENCER)->orderBy('sort_order')->get();

        $latestIn = fn (string $type, ?Category $category) => $category
            ? Project::open()->with($card)->filter(['type' => $type, 'category' => $category->slug])->latest()->limit($tabLimit)->get()
            : collect();

        return [
            'featuredProfiles' => Profile::featured()->with(['user', 'country', 'city'])->orderByDesc('rating')->orderBy('id')->limit($featuredLimit)->get(),
            'distinctiveProjects' => Project::open()->distinctive()->with($card)->filter(['type' => Project::TYPE_PROJECT])->latest()->limit($featuredLimit)->get(),
            'projectCategories' => $projectCategories,
            'recentProjects' => $latestIn(Project::TYPE_PROJECT, $projectCategories->first()),
            'influencerPlatforms' => $influencerPlatforms,
            'latestInfluencerRequests' => $latestIn(Project::TYPE_INFLUENCER_REQUEST, $influencerPlatforms->first()),
        ];
    }
}
