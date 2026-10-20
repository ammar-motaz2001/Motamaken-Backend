<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Medal;
use App\Models\User;
use App\Models\UserMedal;
use App\Services\RewardsService;
use App\Support\Paged;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminMedalController extends Controller
{
    public function __construct(private readonly RewardsService $rewards) {}

    /**
     * Medals.
     *
     * Every medal, active or not, with how many users hold it.
     *
     * @return array{data: list<array<string, mixed>>, metrics: list<string>}
     */
    public function index(): array
    {
        $holders = UserMedal::selectRaw('code, count(*) as total')->groupBy('code')->pluck('total', 'code');

        return [
            'data' => Medal::orderBy('sort_order')->orderBy('id')->get()->map(fn (Medal $m) => self::present($m, (int) ($holders[$m->code] ?? 0)))->all(),
            /** What a medal can be earned on automatically. */
            'metrics' => Medal::METRICS,
        ];
    }

    /**
     * Create a medal.
     *
     * With a `metric` and `target` it is earned automatically; without a metric only admins give it.
     */
    public function store(Request $request): JsonResponse
    {
        $medal = Medal::create($this->validated($request));

        return response()->json(['data' => self::present($medal)], 201);
    }

    /**
     * Update a medal.
     *
     * `isActive: false` stops awarding and showing it; users who hold it keep it.
     *
     * @return array{data: array<string, mixed>}
     */
    public function update(Request $request, int $medal): array
    {
        $medal = Medal::findOrFail($medal);
        request()->attributes->set('audit.before', $medal->only(['name_en', 'name_ar', 'metric', 'target', 'is_active']));
        $medal->update($this->validated($request, $medal));

        return ['data' => self::present($medal, UserMedal::where('code', $medal->code)->count())];
    }

    /**
     * Holders of a medal.
     *
     * Who has it, since when and whether it was given by hand.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function holders(Request $request, int $medal): array
    {
        $medal = Medal::findOrFail($medal);
        $page = UserMedal::where('code', $medal->code)->latest('awarded_at')->paginate($request->integer('perPage', 20));
        $users = User::whereIn('id', collect($page->items())->pluck('user_id'))->get()->keyBy('id');
        $admins = User::whereIn('id', collect($page->items())->pluck('awarded_by')->filter())->get()->keyBy('id');

        return Paged::of($page, fn (UserMedal $m) => [
            'user' => Paged::user($users[$m->user_id] ?? null), 'awardedAt' => $m->awarded_at?->toIso8601String(),
            'manual' => $m->awarded_by !== null, 'awardedBy' => Paged::user($admins[$m->awarded_by] ?? null), 'reason' => $m->reason,
        ]);
    }

    /**
     * Give a medal.
     *
     * By hand, with a reason kept in the record.
     *
     * @return array{success: bool}
     */
    public function grant(Request $request, int $medal): array
    {
        $data = $request->validate(['userId' => ['required', 'integer', 'exists:users,id'], 'reason' => ['required', 'string', 'min:3', 'max:255']]);
        $medal = Medal::findOrFail($medal);

        if (! $this->rewards->giveMedal(User::find($data['userId']), $medal, $request->user(), $data['reason'])) {
            abort(response()->json(['message' => __('admin.medal_held'), 'code' => 'medal_held'], 422));
        }

        return ['success' => true];
    }

    /**
     * Take a medal back.
     *
     * @return array{success: bool}
     */
    public function revoke(Request $request, int $medal, int $user): array
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        UserMedal::where('code', Medal::findOrFail($medal)->code)->where('user_id', $user)->firstOrFail()->delete();

        return ['success' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Medal $medal = null): array
    {
        $sometimes = $medal ? 'sometimes' : 'required';
        $data = $request->validate([
            'code' => [$medal ? 'prohibited' : 'required', 'string', 'alpha_dash', 'max:40', Rule::unique('medals', 'code')],
            'nameEn' => [$sometimes, 'string', 'max:80'],
            'nameAr' => [$sometimes, 'string', 'max:80'],
            'descriptionEn' => ['nullable', 'string', 'max:255'],
            'descriptionAr' => ['nullable', 'string', 'max:255'],
            /** A Lucide icon name. */
            'icon' => ['nullable', 'string', 'max:40'],
            /** Null for medals given by hand only. */
            'metric' => ['nullable', Rule::in(Medal::METRICS)],
            'target' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'isActive' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);

        $map = ['code' => 'code', 'nameEn' => 'name_en', 'nameAr' => 'name_ar', 'descriptionEn' => 'description_en', 'descriptionAr' => 'description_ar',
            'icon' => 'icon', 'metric' => 'metric', 'target' => 'target', 'isActive' => 'is_active', 'sortOrder' => 'sort_order'];

        return collect($map)->filter(fn ($column, $input) => array_key_exists($input, $data))->mapWithKeys(fn ($column, $input) => [$column => $data[$input]])->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Medal $m, int $holders = 0): array
    {
        return [
            'id' => $m->id, 'code' => $m->code, 'nameEn' => $m->name_en, 'nameAr' => $m->name_ar,
            'descriptionEn' => $m->description_en, 'descriptionAr' => $m->description_ar, 'icon' => $m->icon,
            'metric' => $m->metric, 'target' => $m->target, 'isActive' => $m->is_active, 'sortOrder' => $m->sort_order, 'holders' => $holders,
        ];
    }
}
