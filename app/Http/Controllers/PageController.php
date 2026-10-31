<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\Page;
use Dedoc\Scramble\Attributes\Group;

#[Group('Site pages', 'The information pages of the footer and the contact form.', weight: 14)]
class PageController extends Controller
{
    /**
     * List pages.
     *
     * The published information pages, by section (`about`, `help`, `legal`), for the footer and the sitemap.
     *
     * @unauthenticated
     *
     * @return array{data: list<array{slug: string, section: string, title: string, updatedAt: ?string}>}
     */
    public function index(): array
    {
        return ['data' => Page::where('is_published', true)->orderBy('section')->orderBy('sort_order')->get()
            ->map(fn (Page $page) => [
                'slug' => $page->slug, 'section' => $page->section, 'title' => $page->title(),
                'updatedAt' => $page->updated_at?->toIso8601String(),
            ])->all()];
    }

    /**
     * Show a page.
     *
     * Title and body in the language of the `Accept-Language` header. The body is Markdown, with the live fees and limits
     * already filled in.
     *
     * @unauthenticated
     *
     * An unpublished page answers 404 with `code: page_unpublished`. `seo` holds the admin-written search title and description (null: use the page's own) and whether to stay out of search results.
     *
     * @return array{data: array{slug: string, section: string, title: string, body: string, seo: array{title: ?string, description: ?string, noindex: bool}, updatedAt: ?string}}
     */
    public function show(string $slug): array
    {
        $page = Page::where('slug', $slug)->firstOrFail();
        // A page the admins hid answers 404 with its own code, so the website can send visitors home instead.
        if (! $page->is_published) {
            throw new ApiException(__('errors.not_found'), 'page_unpublished', 404);
        }

        return ['data' => [
            'slug' => $page->slug, 'section' => $page->section, 'title' => $page->title(), 'body' => $page->body(),
            'seo' => $page->seo(),
            'updatedAt' => $page->updated_at?->toIso8601String(),
        ]];
    }
}
