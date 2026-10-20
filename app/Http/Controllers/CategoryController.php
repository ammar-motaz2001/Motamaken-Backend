<?php

namespace App\Http\Controllers;

use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

#[Group('Homepage', 'Public data for the homepage, search bar and category menus. No login needed.', weight: 2)]
class CategoryController extends Controller
{
    /**
     * List categories.
     *
     * Project categories (the "Categories" menu, Projects tab) and influencer platforms (Influencers tab).
     * Without `type`, both are returned.
     *
     * @unauthenticated
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            /** `project` for the Projects tab, `influencer` for the Influencers tab. */
            'type' => ['nullable', Rule::in([Category::TYPE_PROJECT, Category::TYPE_INFLUENCER])],
        ]);

        return CategoryResource::collection(
            Category::query()
                ->when($request->input('type'), fn ($query, $type) => $query->ofType($type))
                // Archived categories stay on old projects but are not offered any more.
                ->where('is_active', true)
                ->orderBy('type')
                ->orderBy('sort_order')
                ->get(),
        );
    }
}
