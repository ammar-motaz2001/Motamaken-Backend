<?php

namespace App\Http\Controllers;

use App\Models\Faq;
use App\Models\FaqCategory;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

#[Group('Site pages', 'The information pages of the footer and the contact form.', weight: 14)]
class FaqController extends Controller
{
    /**
     * FAQ.
     *
     * The published questions in their groups, in the reader's language. Narrow with `audience` (the questions for
     * everyone are always included) and search with `q`.
     *
     * @unauthenticated
     *
     * @return array{data: list<array{id: int, name: string, faqs: list<array{id: int, question: string, answer: string, audience: string}>}>}
     */
    public function index(Request $request): array
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'audience' => ['nullable', Rule::in(['client', 'freelancer', 'influencer'])],
        ]);
        $ar = app()->getLocale() === 'ar';

        $groups = Cache::remember('faqs:'.app()->getLocale(), 300, fn () => FaqCategory::where('is_active', true)
            ->with(['faqs' => fn ($q) => $q->where('is_published', true)])->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (FaqCategory $c) => [
                'id' => $c->id,
                'name' => $ar ? $c->name_ar : $c->name_en,
                'faqs' => $c->faqs->map(fn (Faq $f) => [
                    'id' => $f->id, 'question' => $ar ? $f->question_ar : $f->question_en, 'answer' => $ar ? $f->answer_ar : $f->answer_en, 'audience' => $f->audience,
                ])->all(),
            ])->all());

        $term = mb_strtolower(trim((string) $request->input('q')));
        $audience = $request->input('audience');

        return ['data' => collect($groups)->map(fn ($group) => [...$group, 'faqs' => array_values(array_filter($group['faqs'], fn ($f) => (! $audience || in_array($f['audience'], ['all', $audience], true))
            && ($term === '' || str_contains(mb_strtolower($f['question'].' '.$f['answer']), $term))))])
            ->filter(fn ($group) => $group['faqs'] !== [])->values()->all()];
    }
}
