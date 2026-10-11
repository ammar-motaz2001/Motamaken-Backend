<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Models\Subscription;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminPlanController extends Controller
{
    /**
     * List plans.
     *
     * Every plan, including switched-off ones, with how many people are on each.
     */
    public function index(): AnonymousResourceCollection
    {
        $counts = Subscription::inEffect()->selectRaw('plan_id, count(*) as total')->groupBy('plan_id')->pluck('total', 'plan_id');

        return PlanResource::collection(
            Plan::orderBy('audience')->orderBy('sort_order')->get()->map(fn (Plan $plan) => tap($plan, fn () => $plan->subscribers = (int) ($counts[$plan->id] ?? 0))),
        );
    }

    /**
     * Change a plan.
     *
     * Send only what changes. A new price applies from the next renewal; people already on the plan keep their current period.
     */
    public function update(Request $request, int $plan): PlanResource
    {
        $data = $request->validate([
            'price' => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
            'isActive' => ['sometimes', 'boolean'],
            'isPopular' => ['sometimes', 'boolean'],
            /** `monthly_offers` (null = unlimited), `fee_percent`, `promotion_discount_percent`, `priority_support`. */
            'features' => ['sometimes', 'array'],
            'features.monthly_offers' => ['nullable', 'integer', 'min:1'],
            'features.fee_percent' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'features.promotion_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:90'],
            'features.priority_support' => ['nullable', 'boolean'],
        ]);

        $plan = Plan::findOrFail($plan);
        $plan->fill(array_filter([
            'price_cents' => isset($data['price']) ? (int) round($data['price'] * 100) : null,
            'is_active' => $data['isActive'] ?? null,
            'is_popular' => $data['isPopular'] ?? null,
        ], fn ($value) => $value !== null));

        if (isset($data['features'])) {
            $plan->features = [...$plan->features, ...$data['features']];
        }

        $plan->save();

        return new PlanResource($plan);
    }
}
