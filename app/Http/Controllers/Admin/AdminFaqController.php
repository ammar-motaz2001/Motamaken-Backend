<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use App\Models\FaqCategory;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminFaqController extends Controller
{
    /**
     * FAQ.
     *
     * Every group with its questions, in display order, published or not.
     *
     * @return array{data: list<array<string, mixed>>}
     */
    public function index(): array
    {
        return ['data' => FaqCategory::with('faqs')->orderBy('sort_order')->orderBy('id')->get()->map(fn (FaqCategory $c) => [
            ...self::category($c),
            'faqs' => $c->faqs->map(fn (Faq $f) => self::faq($f))->all(),
        ])->all()];
    }

    /**
     * Add a FAQ group.
     */
    public function storeCategory(Request $request): JsonResponse
    {
        $category = FaqCategory::create($this->categoryData($request));
        self::forget();

        return response()->json(['data' => self::category($category)], 201);
    }

    /**
     * Edit a FAQ group.
     *
     * @return array{data: array<string, mixed>}
     */
    public function updateCategory(Request $request, int $category): array
    {
        $category = FaqCategory::findOrFail($category);
        $category->update($this->categoryData($request, true));
        self::forget();

        return ['data' => self::category($category)];
    }

    /**
     * Delete a FAQ group and its questions.
     *
     * @return array{success: bool}
     */
    public function destroyCategory(int $category): array
    {
        FaqCategory::findOrFail($category)->delete();
        self::forget();

        return ['success' => true];
    }

    /**
     * Add a question.
     */
    public function store(Request $request): JsonResponse
    {
        $faq = Faq::create($this->faqData($request));
        self::forget();

        return response()->json(['data' => self::faq($faq)], 201);
    }

    /**
     * Edit a question.
     *
     * `isPublished: false` hides it from the site.
     *
     * @return array{data: array<string, mixed>}
     */
    public function update(Request $request, int $faq): array
    {
        $faq = Faq::findOrFail($faq);
        $faq->update($this->faqData($request, true));
        self::forget();

        return ['data' => self::faq($faq)];
    }

    /**
     * Delete a question.
     *
     * @return array{success: bool}
     */
    public function destroy(int $faq): array
    {
        Faq::findOrFail($faq)->delete();
        self::forget();

        return ['success' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function categoryData(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'nameEn' => [$required, 'string', 'max:100'], 'nameAr' => [$required, 'string', 'max:100'],
            'sortOrder' => ['sometimes', 'integer', 'min:0', 'max:65000'], 'isActive' => ['sometimes', 'boolean'],
        ]);

        return collect(['nameEn' => 'name_en', 'nameAr' => 'name_ar', 'sortOrder' => 'sort_order', 'isActive' => 'is_active'])
            ->filter(fn ($c, $i) => array_key_exists($i, $data))->mapWithKeys(fn ($c, $i) => [$c => $data[$i]])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function faqData(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'categoryId' => [$required, 'integer', 'exists:faq_categories,id'],
            'questionEn' => [$required, 'string', 'max:255'], 'questionAr' => [$required, 'string', 'max:255'],
            'answerEn' => [$required, 'string', 'max:5000'], 'answerAr' => [$required, 'string', 'max:5000'],
            /** Who it is for: `all`, `client`, `freelancer` or `influencer`. */
            'audience' => ['sometimes', Rule::in(Faq::AUDIENCES)],
            'isPublished' => ['sometimes', 'boolean'], 'sortOrder' => ['sometimes', 'integer', 'min:0', 'max:65000'],
        ]);

        return collect(['categoryId' => 'faq_category_id', 'questionEn' => 'question_en', 'questionAr' => 'question_ar', 'answerEn' => 'answer_en',
            'answerAr' => 'answer_ar', 'audience' => 'audience', 'isPublished' => 'is_published', 'sortOrder' => 'sort_order'])
            ->filter(fn ($c, $i) => array_key_exists($i, $data))->mapWithKeys(fn ($c, $i) => [$c => $data[$i]])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function category(FaqCategory $c): array
    {
        return ['id' => $c->id, 'nameEn' => $c->name_en, 'nameAr' => $c->name_ar, 'sortOrder' => $c->sort_order, 'isActive' => (bool) $c->is_active];
    }

    /**
     * @return array<string, mixed>
     */
    private static function faq(Faq $f): array
    {
        return [
            'id' => $f->id, 'categoryId' => $f->faq_category_id, 'questionEn' => $f->question_en, 'questionAr' => $f->question_ar,
            'answerEn' => $f->answer_en, 'answerAr' => $f->answer_ar, 'audience' => $f->audience, 'isPublished' => (bool) $f->is_published, 'sortOrder' => $f->sort_order,
        ];
    }

    public static function forget(): void
    {
        foreach (['en', 'ar', 'fr'] as $locale) {
            Cache::forget("faqs:{$locale}");
        }
    }
}
