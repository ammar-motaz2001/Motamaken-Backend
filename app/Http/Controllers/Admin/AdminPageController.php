<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminPageController extends Controller
{
    /**
     * List pages.
     *
     * Every information page, published or not, with both languages.
     *
     * @return array{data: list<array<string, mixed>>, placeholders: list<string>}
     */
    public function index(): array
    {
        return [
            'data' => Page::orderBy('section')->orderBy('sort_order')->get()->map(fn (Page $page) => $this->present($page))->all(),
            /** What can be written in a body and is replaced with the live value (e.g. `{{site_fee}}`). */
            'placeholders' => array_keys(Page::placeholders()),
        ];
    }

    /**
     * Show a page.
     *
     * @return array{data: array<string, mixed>}
     */
    public function show(string $slug): array
    {
        return ['data' => $this->present(Page::where('slug', $slug)->firstOrFail())];
    }

    /**
     * Save a page.
     *
     * Creates the page or replaces it. Bodies are Markdown and may use the placeholders listed by `GET /admin/pages`.
     *
     * @return array{data: array<string, mixed>}
     */
    public function update(Request $request, string $slug): array
    {
        abort_unless(preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) && strlen($slug) <= 60, 404);

        $data = $request->validate([
            'section' => ['required', Rule::in(Page::SECTIONS)],
            'titleEn' => ['required', 'string', 'max:150'],
            'titleAr' => ['required', 'string', 'max:150'],
            'bodyEn' => ['required', 'string', 'max:100000'],
            'bodyAr' => ['required', 'string', 'max:100000'],
            'isPublished' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);

        $page = Page::updateOrCreate(['slug' => $slug], [
            'section' => $data['section'], 'title_en' => $data['titleEn'], 'title_ar' => $data['titleAr'],
            'body_en' => $data['bodyEn'], 'body_ar' => $data['bodyAr'],
            'is_published' => $data['isPublished'] ?? true, 'sort_order' => $data['sortOrder'] ?? 0,
            'updated_by' => $request->user()->id,
        ]);

        return ['data' => $this->present($page)];
    }

    /**
     * Delete a page.
     *
     * @return array{success: bool}
     */
    public function destroy(string $slug): array
    {
        Page::where('slug', $slug)->firstOrFail()->delete();

        return ['success' => true];
    }

    /**
     * A content page in both languages for the admin screens.
     *
     * @return array<string, mixed>
     */
    private function present(Page $page): array
    {
        return [
            'slug' => $page->slug, 'section' => $page->section,
            'titleEn' => $page->title_en, 'titleAr' => $page->title_ar, 'bodyEn' => $page->body_en, 'bodyAr' => $page->body_ar,
            'isPublished' => $page->is_published, 'sortOrder' => $page->sort_order,
            'updatedAt' => $page->updated_at?->toIso8601String(),
        ];
    }
}
