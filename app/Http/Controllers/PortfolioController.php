<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\PortfolioItem;
use App\Services\RewardsService;
use App\Support\RuntimeConfig;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

#[Group('Profiles & reviews', 'Public profiles, portfolio, reviews and content reports.', weight: 5)]
class PortfolioController extends Controller
{
    private const MAX_ITEMS = 30;

    /**
     * Add a portfolio item.
     *
     * `multipart/form-data`: `title`, `description?`, `link?` and an `image` (JPEG, PNG or WebP up to 8 MB).
     * Needs a profile (`profile_required`).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([...$this->rules(), 'image' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:'.RuntimeConfig::uploadKb('portfolio')]]);
        $profile = $request->user()->profile ?? throw new ApiException(__('proposals.profile_required'), 'profile_required');

        if ($profile->portfolio()->count() >= self::MAX_ITEMS) {
            throw new ApiException(__('profiles.portfolio_full', ['max' => self::MAX_ITEMS]), 'portfolio_full');
        }

        $item = $profile->portfolio()->create([
            'title' => $data['title'], 'description' => $data['description'] ?? null, 'link' => $data['link'] ?? null,
            'image_path' => $request->file('image')->store('portfolio', 'public'),
            'position' => (int) $profile->portfolio()->max('position') + 1,
        ]);

        app(RewardsService::class)->checkProfileCompleted($request->user());

        return response()->json(['data' => $this->present($item)], 201);
    }

    /**
     * Edit a portfolio item.
     *
     * Send `image` too (as `multipart/form-data` with `_method=PATCH`) to replace the picture.
     *
     * @return array{data: array<string, mixed>}
     */
    public function update(Request $request, int $item): array
    {
        $item = $this->own($request, $item);
        $data = $request->validate([...$this->rules(), 'image' => ['sometimes', 'image', 'mimes:jpeg,png,webp', 'max:'.RuntimeConfig::uploadKb('portfolio')]]);

        if ($request->hasFile('image')) {
            Storage::disk('public')->delete($item->image_path);
            $item->image_path = $request->file('image')->store('portfolio', 'public');
        }
        $item->fill(['title' => $data['title'], 'description' => $data['description'] ?? null, 'link' => $data['link'] ?? null])->save();

        return ['data' => $this->present($item)];
    }

    /**
     * Delete a portfolio item.
     *
     * @return array{success: bool}
     */
    public function destroy(Request $request, int $item): array
    {
        $item = $this->own($request, $item);
        Storage::disk('public')->delete($item->image_path);
        $item->delete();

        return ['success' => true];
    }

    /**
     * Validation for a portfolio item's title, description and link.
     *
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'link' => ['nullable', 'url', 'max:500'],
        ];
    }

    /**
     * Finds a portfolio item on the current user's profile, or fails with 404.
     */
    private function own(Request $request, int $id): PortfolioItem
    {
        return PortfolioItem::whereIn('profile_id', $request->user()->profile()->select('id'))->findOrFail($id);
    }

    /**
     * A portfolio item for the API.
     *
     * @return array<string, mixed>
     */
    private function present(PortfolioItem $item): array
    {
        return ['id' => $item->id, 'title' => $item->title, 'description' => $item->description, 'imageUrl' => $item->image_url, 'link' => $item->link];
    }
}
