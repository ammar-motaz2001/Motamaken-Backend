<?php

namespace App\Http\Controllers;

use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Services\SubscriptionService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

#[Group('Subscriptions & promotions', 'Monthly plans, and paid placement on the homepage ("Premium Services").', weight: 11)]
class PlanController extends Controller
{
    /**
     * List plans.
     *
     * The plans page: Free and paid plans for each account type, cheapest first. With a token, `isCurrent` marks the
     * plan the user is on.
     *
     * @unauthenticated
     */
    public function index(Request $request, SubscriptionService $subscriptions): AnonymousResourceCollection
    {
        $request->validate([
            /** The tab: `freelancer`, `client` or `influencer`. */
            'audience' => ['nullable', Rule::in(Plan::AUDIENCES)],
        ]);

        $paid = ($user = $request->user('sanctum')) ? $subscriptions->planOf($user) : null;

        $plans = Plan::where('is_active', true)
            ->when($request->input('audience'), fn ($query, $audience) => $query->where('audience', $audience))
            ->orderBy('audience')->orderBy('sort_order')->get();

        // A plain loop: Collection::each() would stop at the first callback that returns false.
        foreach ($plans as $plan) {
            $plan->is_current = $user ? ($paid ? $paid->id === $plan->id : $plan->isFree()) : null;
        }

        return PlanResource::collection($plans);
    }
}
