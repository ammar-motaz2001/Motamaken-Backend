<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Money;
use App\Support\Paged;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminSubscriptionController extends Controller
{
    /**
     * Subscriptions.
     *
     * Who pays for which plan, the current period and whether it renews. Newest first, with counts per status and plan.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>, counts: array<string, int>, byPlan: list<array{plan: string, active: int}>}
     */
    public function index(Request $request): array
    {
        $request->validate([
            /** `active`, `past_due`, `cancelled` or `expired`. */
            'status' => ['nullable', Rule::in([Subscription::ACTIVE, Subscription::PAST_DUE, Subscription::CANCELLED, Subscription::EXPIRED])],
            'planId' => ['nullable', 'integer'],
            'userId' => ['nullable', 'integer'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->filtered($request)->with(['user', 'plan'])->latest('id')->paginate($request->integer('perPage', 20));

        return Paged::of($page, fn (Subscription $s) => self::present($s), [
            'counts' => Subscription::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n)->all(),
            'byPlan' => Plan::withCount(['subscriptions as active' => fn ($q) => $q->whereIn('status', [Subscription::ACTIVE, Subscription::PAST_DUE])])
                ->orderBy('audience')->orderBy('sort_order')->get()
                ->map(fn (Plan $p) => ['planId' => $p->id, 'plan' => $p->name_en, 'planAr' => $p->name_ar, 'audience' => $p->audience, 'active' => (int) $p->active])->all(),
        ]);
    }

    /**
     * @return Builder<Subscription>
     */
    public function filtered(Request $request): Builder
    {
        return Subscription::query()
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('planId'), fn ($q, $id) => $q->where('plan_id', $id))
            ->when($request->input('userId'), fn ($q, $id) => $q->where('user_id', $id));
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Subscription $s): array
    {
        return [
            'id' => $s->id,
            'user' => Paged::user($s->user),
            'plan' => $s->plan ? ['id' => $s->plan->id, 'nameEn' => $s->plan->name_en, 'nameAr' => $s->plan->name_ar, 'audience' => $s->plan->audience,
                'price' => Money::fromCents((int) $s->plan->price_cents), 'currency' => $s->plan->currency] : null,
            'status' => $s->status,
            'autoRenew' => (bool) $s->auto_renew,
            'currentPeriodStart' => $s->current_period_start?->toIso8601String(),
            'currentPeriodEnd' => $s->current_period_end?->toIso8601String(),
            'cancelledAt' => $s->cancelled_at?->toIso8601String(),
            'createdAt' => $s->created_at?->toIso8601String(),
        ];
    }
}
