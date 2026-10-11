<?php

namespace App\Http\Controllers;

use App\Http\Requests\SearchRequest;
use App\Http\Resources\ProfileCardResource;
use App\Http\Resources\ProjectResource;
use App\Models\Profile;
use App\Models\Project;
use App\Support\LikeSearch;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Homepage', 'Public data for the homepage, search bar and category menus. No login needed.', weight: 2)]
class SearchController extends Controller
{
    private const PREVIEW_LIMIT = 5;

    /**
     * Search.
     *
     * The header search bar. With `type=all` (default) it returns up to 5 results from each group; with a single type
     * (`projects`, `influencer_requests`, `influencers`, `freelancers`) it returns that group paginated.
     *
     * @unauthenticated
     *
     * @return array{projects: AnonymousResourceCollection, influencerRequests: AnonymousResourceCollection, influencers: AnonymousResourceCollection, freelancers: AnonymousResourceCollection}|AnonymousResourceCollection
     */
    public function __invoke(SearchRequest $request): array|AnonymousResourceCollection
    {
        $filters = $request->validated();
        $type = $request->input('type', 'all');

        $queries = [
            'projects' => fn () => $this->projects($filters, Project::TYPE_PROJECT),
            'influencer_requests' => fn () => $this->projects($filters, Project::TYPE_INFLUENCER_REQUEST),
            'influencers' => fn () => $this->profiles($filters, Profile::TYPE_INFLUENCER),
            'freelancers' => fn () => $this->profiles($filters, Profile::TYPE_FREELANCER),
        ];

        if ($type !== 'all') {
            $results = $queries[$type]()->paginate($request->integer('perPage', 15));

            return in_array($type, ['projects', 'influencer_requests'], true)
                ? ProjectResource::collection($results)
                : ProfileCardResource::collection($results);
        }

        return [
            'projects' => ProjectResource::collection($queries['projects']()->limit(self::PREVIEW_LIMIT)->get()),
            'influencerRequests' => ProjectResource::collection($queries['influencer_requests']()->limit(self::PREVIEW_LIMIT)->get()),
            'influencers' => ProfileCardResource::collection($queries['influencers']()->limit(self::PREVIEW_LIMIT)->get()),
            'freelancers' => ProfileCardResource::collection($queries['freelancers']()->limit(self::PREVIEW_LIMIT)->get()),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Project>
     */
    private function projects(array $filters, string $type): Builder
    {
        $query = Project::open()
            ->with(['user', 'category', 'country', 'city'])
            ->filter([...$filters, 'type' => $type]);

        return match ($filters['sort'] ?? 'newest') {
            'budget_high' => $query->orderByDesc('projects.budget_max')->orderByDesc('projects.id'),
            'budget_low' => $query->orderBy('projects.budget_min')->orderByDesc('projects.id'),
            default => $query->latest('projects.created_at')->latest('projects.id'),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Profile>
     */
    private function profiles(array $filters, string $type): Builder
    {
        return Profile::query()
            ->with(['user', 'country', 'city'])
            ->where('profiles.type', $type)
            ->when($filters['q'] ?? null, fn ($query, $term) => LikeSearch::apply($query, [
                'profiles.job_title', 'users.first_name', 'users.last_name', 'users.username',
            ], $term)->join('users', 'users.id', '=', 'profiles.user_id')->select('profiles.*'))
            ->when($filters['countryId'] ?? null, fn ($query, $id) => $query->where('profiles.country_id', $id))
            ->when($filters['cityId'] ?? null, fn ($query, $id) => $query->where('profiles.city_id', $id))
            ->when($filters['minRating'] ?? null, fn ($query, $rating) => $query->where('profiles.rating', '>=', $rating))
            // Skills and channels are stored as JSON text: match inside it, the same way on Postgres and SQLite.
            ->when($filters['skill'] ?? null, fn ($query, $skill) => $query->whereRaw(
                "lower(cast(profiles.skills as text)) like ? escape '\\'", ['%'.$this->jsonLike(mb_strtolower(trim($skill))).'%'],
            ))
            ->when($filters['platform'] ?? null, fn ($query, $platform) => $query->whereRaw(
                "cast(profiles.channels as text) like ? escape '\\'", ['%"platform":"'.$this->jsonLike(mb_strtolower($platform)).'"%'],
            ))
            ->when(($filters['sort'] ?? 'rating') === 'reviews', fn ($query) => $query->orderByDesc('profiles.reviews_count'))
            ->when(($filters['sort'] ?? 'rating') === 'newest', fn ($query) => $query->orderByDesc('profiles.created_at'))
            ->orderByDesc('profiles.rating')
            ->orderBy('profiles.id');
    }

    /**
     * Skill suggestions.
     *
     * The most listed skills, for the "Choose skills" picker of the advanced search. Filter with `q`.
     *
     * @unauthenticated
     *
     * @return array{data: list<string>}
     */
    public function skills(Request $request): array
    {
        $term = mb_strtolower(trim((string) $request->query('q')));

        $counts = Profile::whereNotNull('skills')->latest('updated_at')->limit(2000)->pluck('skills')
            ->flatten()->filter(fn ($skill) => is_string($skill) && ($term === '' || str_contains(mb_strtolower($skill), $term)))
            ->countBy(fn ($skill) => trim($skill));

        return ['data' => $counts->sortDesc()->keys()->take(30)->values()->all()];
    }

    /**
     * A term as it appears inside stored JSON (non-ASCII letters are kept as \uXXXX), escaped for LIKE.
     */
    private function jsonLike(string $value): string
    {
        return addcslashes(trim((string) json_encode($value), '"'), '%_\\');
    }
}
