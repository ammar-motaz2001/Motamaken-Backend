<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Resources\ProfileCardResource;
use App\Http\Resources\ProjectResource;
use App\Models\Favorite;
use App\Models\Profile;
use App\Models\Project;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Favorites', 'The heart icon: projects and profiles a user saved.', weight: 13)]
class FavoriteController extends Controller
{
    /**
     * My favorites.
     *
     * Saved projects (`type=project`, the default) or saved profiles (`type=profile`), most recently saved first.
     * Projects that are no longer open are kept, with their status, so the user sees what happened to them.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    public function index(Request $request): array
    {
        $data = $request->validate([
            'type' => ['nullable', Rule::in(Favorite::TYPES)],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $type = $data['type'] ?? Favorite::PROJECT;

        $page = Favorite::where('user_id', $request->user()->id)->where('type', $type)
            ->latest('id')->paginate($request->integer('perPage', 15));
        $ids = $page->getCollection()->pluck('target_id');

        $items = $type === Favorite::PROJECT
            ? Project::with(['user', 'category', 'country', 'city'])->whereIn('id', $ids)->get()->keyBy('id')
            : Profile::with(['user', 'country', 'city'])->whereIn('id', $ids)->get()->keyBy('id');

        return [
            'data' => $page->getCollection()
                ->filter(fn (Favorite $favorite) => isset($items[$favorite->target_id]))
                ->map(fn (Favorite $favorite) => [
                    'type' => $type,
                    'savedAt' => $favorite->created_at?->toIso8601String(),
                    'item' => ($type === Favorite::PROJECT ? new ProjectResource($items[$favorite->target_id]) : new ProfileCardResource($items[$favorite->target_id]))->resolve(),
                ])->values()->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ];
    }

    /**
     * Saved ids.
     *
     * Every id the user saved, to fill the hearts on project and profile cards in one call.
     *
     * @return array{projects: list<int>, profiles: list<int>}
     */
    public function ids(Request $request): array
    {
        $favorites = Favorite::where('user_id', $request->user()->id)->get(['type', 'target_id'])->groupBy('type');

        return [
            'projects' => $favorites->get(Favorite::PROJECT, collect())->pluck('target_id')->map(fn ($id) => (int) $id)->values()->all(),
            'profiles' => $favorites->get(Favorite::PROFILE, collect())->pluck('target_id')->map(fn ($id) => (int) $id)->values()->all(),
        ];
    }

    /**
     * Save.
     *
     * Fill the heart. Saving something already saved changes nothing (200 instead of 201). Your own projects and
     * profile cannot be saved.
     *
     * @return array{saved: bool}
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            /** `project` or `profile`. */
            'type' => ['required', Rule::in(Favorite::TYPES)],
            /** The project id, or the profile id (the `id` of a profile card, not the user id). */
            'id' => ['required', 'integer'],
        ]);

        $ownerId = $data['type'] === Favorite::PROJECT
            ? Project::whereKey($data['id'])->value('user_id')
            : Profile::whereKey($data['id'])->value('user_id');

        abort_if($ownerId === null, 404);

        if ($ownerId === $request->user()->id) {
            throw new ApiException(__('favorites.own'), 'own_item');
        }

        $favorite = Favorite::firstOrCreate(['user_id' => $request->user()->id, 'type' => $data['type'], 'target_id' => $data['id']]);

        return response()->json(['saved' => true], $favorite->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Remove.
     *
     * Empty the heart. Removing something not saved is not an error.
     *
     * @return array{saved: bool}
     */
    public function destroy(Request $request, string $type, int $id): array
    {
        abort_unless(in_array($type, Favorite::TYPES, true), 404);

        Favorite::where(['user_id' => $request->user()->id, 'type' => $type, 'target_id' => $id])->delete();

        return ['saved' => false];
    }
}
