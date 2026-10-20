<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Promotion;
use App\Models\Setting;
use App\Support\Money;
use App\Support\Paged;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminPromotionController extends Controller
{
    /**
     * Promotions.
     *
     * Paid placements in the homepage sliders: what was promoted, by whom, for how long and for how much. Newest first.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>, summary: array{active: int, total: int}}
     */
    public function index(Request $request): array
    {
        $request->validate([
            /** `profile` or `project`. */
            'kind' => ['nullable', Rule::in([Promotion::PROFILE, Promotion::PROJECT])],
            /** `active` (running now), `scheduled` or `ended`. */
            'state' => ['nullable', Rule::in(['active', 'scheduled', 'ended'])],
            'userId' => ['nullable', 'integer'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->filtered($request)->with(['user', 'project'])->latest('id')->paginate($request->integer('perPage', 20));

        return Paged::of($page, fn (Promotion $p) => self::present($p), [
            'summary' => ['active' => Promotion::where('starts_at', '<=', now())->where('ends_at', '>', now())->count(), 'total' => Promotion::count()],
        ]);
    }

    /**
     * @return Builder<Promotion>
     */
    public function filtered(Request $request): Builder
    {
        return Promotion::query()
            ->when($request->input('kind'), fn ($q, $kind) => $q->where('kind', $kind))
            ->when($request->input('userId'), fn ($q, $id) => $q->where('user_id', $id))
            ->when($request->input('state'), fn ($q, $state) => match ($state) {
                'active' => $q->where('starts_at', '<=', now())->where('ends_at', '>', now()),
                'scheduled' => $q->where('starts_at', '>', now()),
                'ended' => $q->where('ends_at', '<=', now()),
            });
    }

    /**
     * End a promotion now.
     *
     * Takes the item out of the slider early (for example after hiding a project). Nothing is refunded automatically.
     *
     * @return array{data: array<string, mixed>}
     */
    public function end(int $promotion): array
    {
        $promotion = Promotion::with(['user', 'project'])->findOrFail($promotion);
        $promotion->update(['ends_at' => now()]);

        if ($promotion->kind === Promotion::PROJECT && $promotion->project_id) {
            Project::whereKey($promotion->project_id)->update(['is_featured' => false, 'featured_until' => null]);
        } else {
            Profile::where('user_id', $promotion->user_id)->update(['is_featured' => false, 'featured_until' => null]);
        }

        return ['data' => self::present($promotion)];
    }

    /**
     * Promotion prices.
     *
     * Price per day of each placement and the packages offered in the "Feature your project / profile" popups, per
     * currency, and the points given for each purchase.
     *
     * @return array{data: array{prices: array<string, array<string, float>>, packages: list<array<string, mixed>>, points: int}}
     */
    public function prices(): array
    {
        return ['data' => [
            'prices' => config('marketplace.promotions.prices'),
            'packages' => config('marketplace.promotions.packages'),
            'points' => (int) config('marketplace.promotions.points'),
        ]];
    }

    /**
     * Update promotion prices.
     *
     * Send only what changes. Applies to new purchases.
     *
     * @return array{data: array{prices: array<string, array<string, float>>, packages: list<array<string, mixed>>, points: int}}
     */
    public function updatePrices(Request $request): array
    {
        $currencies = array_column(config('marketplace.currencies'), 'code');
        $request->validate([
            'prices' => ['sometimes', 'array:profile,project'],
            'prices.*' => ['array:'.implode(',', $currencies)],
            'prices.*.*' => ['numeric', 'min:0', 'max:100000'],
            'packages' => ['sometimes', 'array', 'min:1', 'max:6'],
            'packages.*.code' => ['required', 'string', 'alpha_dash', 'max:20', 'distinct'],
            'packages.*.days' => ['required', 'integer', 'min:1', 'max:90'],
            'packages.*.reach' => ['required', 'array', 'size:2'],
            'packages.*.reach.*' => ['integer', 'min:0'],
            'packages.*.prices' => ['required', 'array:'.implode(',', $currencies)],
            'packages.*.prices.*' => ['numeric', 'min:0', 'max:100000'],
            'points' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ]);
        request()->attributes->set('audit.before', $this->prices()['data']);

        foreach (['prices', 'packages', 'points'] as $key) {
            if ($request->has($key)) {
                Setting::put("promotions.{$key}", $request->input($key));
                config(["marketplace.promotions.{$key}" => $request->input($key)]);
            }
        }

        return $this->prices();
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Promotion $p): array
    {
        return [
            'id' => $p->id,
            'user' => Paged::user($p->user),
            'kind' => $p->kind,
            'package' => $p->package,
            'project' => $p->project ? ['id' => $p->project->id, 'title' => $p->project->title] : null,
            'days' => $p->days,
            'price' => Money::fromCents((int) $p->price_cents),
            'currency' => $p->currency,
            'pointsAwarded' => (int) $p->points_awarded,
            'startsAt' => $p->starts_at?->toIso8601String(),
            'endsAt' => $p->ends_at?->toIso8601String(),
            'active' => $p->starts_at?->isPast() && $p->ends_at?->isFuture(),
            'createdAt' => $p->created_at?->toIso8601String(),
        ];
    }
}
