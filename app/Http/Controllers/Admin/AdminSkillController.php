<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\Skill;
use App\Support\LikeSearch;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminSkillController extends Controller
{
    /**
     * Skills.
     *
     * The curated skills suggested in profiles and the search filters, plus the most used skills users typed that are
     * not on the list yet (to add them in one click).
     *
     * @return array{data: list<array<string, mixed>>, suggestions: list<array{name: string, profiles: int}>}
     */
    public function index(Request $request): array
    {
        $request->validate(['q' => ['nullable', 'string', 'max:80'], 'categoryId' => ['nullable', 'integer']]);
        $known = Skill::pluck('name_en')->merge(Skill::pluck('name_ar'))->map(fn ($n) => mb_strtolower($n))->all();

        return [
            'data' => Skill::with('category')
                ->when($request->input('q'), fn ($q, $term) => LikeSearch::apply($q, ['name_en', 'name_ar', 'slug'], $term))
                ->when($request->input('categoryId'), fn ($q, $id) => $q->where('category_id', $id))
                ->orderBy('sort_order')->orderBy('name_en')->get()->map(fn (Skill $s) => self::present($s))->all(),
            'suggestions' => Profile::whereNotNull('skills')->latest('updated_at')->limit(2000)->pluck('skills')->flatten()
                ->filter(fn ($s) => is_string($s) && trim($s) !== '' && ! in_array(mb_strtolower(trim($s)), $known, true))
                ->countBy(fn ($s) => trim($s))->sortDesc()->take(20)
                ->map(fn ($count, $name) => ['name' => $name, 'profiles' => $count])->values()->all(),
        ];
    }

    /**
     * Add a skill.
     */
    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => self::present(Skill::create($this->validated($request))->load('category'))], 201);
    }

    /**
     * Edit a skill.
     *
     * `isActive: false` stops suggesting it; profiles that list it keep it.
     *
     * @return array{data: array<string, mixed>}
     */
    public function update(Request $request, int $skill): array
    {
        $skill = Skill::findOrFail($skill);
        $skill->update($this->validated($request, $skill));

        return ['data' => self::present($skill->load('category'))];
    }

    /**
     * Delete a skill.
     *
     * Removes it from the suggestions only; profiles keep the text.
     *
     * @return array{success: bool}
     */
    public function destroy(int $skill): array
    {
        Skill::findOrFail($skill)->delete();

        return ['success' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Skill $skill = null): array
    {
        $required = $skill ? 'sometimes' : 'required';
        $data = $request->validate([
            'nameEn' => [$required, 'string', 'max:80'],
            'nameAr' => [$required, 'string', 'max:80'],
            'slug' => ['sometimes', 'string', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('skills', 'slug')->ignore($skill?->id)],
            /** The project category it belongs to, if any. */
            'categoryId' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'isActive' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer', 'min:0', 'max:65000'],
        ]);

        $out = collect(['nameEn' => 'name_en', 'nameAr' => 'name_ar', 'slug' => 'slug', 'categoryId' => 'category_id', 'isActive' => 'is_active', 'sortOrder' => 'sort_order'])
            ->filter(fn ($column, $input) => array_key_exists($input, $data))->mapWithKeys(fn ($column, $input) => [$column => $data[$input]])->all();

        if (! $skill && empty($out['slug'])) {
            $base = str($data['nameEn'])->slug()->limit(55, '')->toString() ?: 'skill';
            $slug = $base;
            for ($i = 2; Skill::where('slug', $slug)->exists(); $i++) {
                $slug = "{$base}-{$i}";
            }
            $out['slug'] = $slug;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Skill $s): array
    {
        return [
            'id' => $s->id, 'slug' => $s->slug, 'nameEn' => $s->name_en, 'nameAr' => $s->name_ar, 'isActive' => $s->is_active, 'sortOrder' => $s->sort_order,
            'category' => $s->category ? ['id' => $s->category->id, 'nameEn' => $s->category->name_en, 'nameAr' => $s->category->name_ar] : null,
        ];
    }
}
