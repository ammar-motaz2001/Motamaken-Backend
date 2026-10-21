<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Project;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminCategoryController extends Controller
{
    /**
     * Categories.
     *
     * Project categories and influencer platforms in display order, with how many projects use each.
     *
     * @return array{data: list<array<string, mixed>>}
     */
    public function index(Request $request): array
    {
        $request->validate(['type' => ['nullable', Rule::in([Category::TYPE_PROJECT, Category::TYPE_INFLUENCER])]]);

        $usage = Project::selectRaw('category_id, count(*) as total')->groupBy('category_id')->pluck('total', 'category_id');

        return ['data' => Category::query()
            ->when($request->input('type'), fn ($q, $type) => $q->where('type', $type))
            ->orderBy('type')->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (Category $c) => $this->present($c, (int) ($usage[$c->id] ?? 0)))->all()];
    }

    /**
     * Add a category.
     *
     * @return array{data: array<string, mixed>}
     */
    public function store(Request $request): array
    {
        $category = Category::create($this->validated($request));
        $this->forget();

        return ['data' => $this->present($category, 0)];
    }

    /**
     * Edit a category.
     *
     * @return array{data: array<string, mixed>}
     */
    public function update(Request $request, int $category): array
    {
        $category = Category::findOrFail($category);
        $category->update($this->validated($request, $category));
        $this->forget();

        return ['data' => $this->present($category, Project::where('category_id', $category->id)->count())];
    }

    /**
     * Delete a category.
     *
     * Only when no project uses it (`category_in_use`); otherwise archive it (`isActive: false`). Its subcategories become
     * top-level ones.
     *
     * @return array{success: bool}
     */
    public function destroy(int $category): array
    {
        $category = Category::findOrFail($category);
        if (Project::where('category_id', $category->id)->exists()) {
            throw new ApiException(__('admin.category_in_use'), 'category_in_use');
        }
        $category->delete();
        $this->forget();

        return ['success' => true];
    }

    /**
     * Validates a category and maps it to columns; a category with subcategories cannot become one itself.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Category $category = null): array
    {
        $type = $request->input('type', $category?->type);
        $data = $request->validate([
            'type' => [$category ? 'sometimes' : 'required', Rule::in([Category::TYPE_PROJECT, Category::TYPE_INFLUENCER])],
            /** Lowercase letters, numbers and dashes; unique within its type. */
            'slug' => [$category ? 'sometimes' : 'required', 'string', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('categories', 'slug')->where('type', $type)->ignore($category?->id)],
            'nameEn' => [$category ? 'sometimes' : 'required', 'string', 'max:120'],
            'nameAr' => [$category ? 'sometimes' : 'required', 'string', 'max:120'],
            /** An icon name the website knows, e.g. `code`, `palette`, `instagram`. */
            'icon' => ['nullable', 'string', 'max:60'],
            'sortOrder' => ['nullable', 'integer', 'min:0', 'max:65000'],
            /** Makes it a subcategory of another top-level category of the same type. */
            'parentId' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('type', $type)->whereNull('parent_id'),
                Rule::notIn(array_filter([$category?->id]))],
            /** `false` archives it: kept on existing projects, no longer offered for new ones. */
            'isActive' => ['sometimes', 'boolean'],
        ]);

        if ($category && ($data['parentId'] ?? null) && Category::where('parent_id', $category->id)->exists()) {
            abort(response()->json(['message' => __('admin.category_has_children'), 'errors' => ['parentId' => [__('admin.category_has_children')]]], 422));
        }

        return collect([
            'type' => $data['type'] ?? null, 'slug' => $data['slug'] ?? null, 'name_en' => $data['nameEn'] ?? null,
            'name_ar' => $data['nameAr'] ?? null, 'icon' => $data['icon'] ?? null, 'sort_order' => $data['sortOrder'] ?? null,
            'parent_id' => $data['parentId'] ?? null, 'is_active' => $data['isActive'] ?? null,
        ])->filter(fn ($v, $k) => array_key_exists(match ($k) {
            'name_en' => 'nameEn', 'name_ar' => 'nameAr', 'sort_order' => 'sortOrder', 'parent_id' => 'parentId', 'is_active' => 'isActive', default => $k,
        }, $data))->map(fn ($v, $k) => $k === 'sort_order' ? (int) $v : $v)->all();
    }

    /**
     * A category for the admin list, with its number of projects.
     *
     * @return array<string, mixed>
     */
    private function present(Category $c, int $projects): array
    {
        return [
            'id' => $c->id, 'type' => $c->type, 'slug' => $c->slug, 'nameEn' => $c->name_en, 'nameAr' => $c->name_ar,
            'icon' => $c->icon, 'sortOrder' => $c->sort_order, 'projects' => $projects,
            'parentId' => $c->parent_id, 'isActive' => (bool) $c->is_active,
        ];
    }

    /** The home page (which lists the categories) is cached per language. */
    private function forget(): void
    {
        foreach (array_column(config('marketplace.languages', [['code' => 'en'], ['code' => 'ar']]), 'code') as $locale) {
            Cache::forget("home:{$locale}");
        }
    }
}
