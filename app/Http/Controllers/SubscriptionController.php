<?php

namespace App\Http\Controllers;

use App\Http\Resources\SubscriptionResource;
use App\Models\Plan;
use App\Services\SubscriptionService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Subscriptions & promotions', 'Monthly plans, and paid placement on the homepage ("Premium Services").', weight: 11)]
class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /**
     * My subscription.
     *
     * The paid plan in effect (null on the free plan), what the user's plan means in numbers, and this month's usage.
     *
     * @return array{subscription: ?SubscriptionResource, benefits: array{offersLimit: ?int, offersSentThisMonth: int, feePercent: float}}
     */
    public function show(Request $request): array
    {
        $user = $request->user();
        $subscription = $user->subscription()->with('plan')->first();

        return [
            'subscription' => $subscription ? new SubscriptionResource($subscription) : null,
            'benefits' => [
                /** Offers allowed per month; null means unlimited. */
                'offersLimit' => $this->subscriptions->monthlyOfferLimit($user),
                'offersSentThisMonth' => $this->subscriptions->offersSentThisMonth($user),
                /** The platform's cut of what the user earns, in percent. */
                'feePercent' => $this->subscriptions->feePercentFor($user),
            ],
        ];
    }

    /**
     * Choose a plan.
     *
     * A paid plan is charged from the wallet at once (`insufficient_funds` otherwise) and renews monthly while
     * `autoRenew` is true. It replaces the current paid plan, with no refund for the unused time. Choosing a free plan lets
     * the paid one run out without renewing (the response then has `subscription` null).
     *
     * @return array{subscription: ?SubscriptionResource}
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'planId' => ['required', 'integer', Rule::exists('plans', 'id')],
            /** Defaults to true. */
            'autoRenew' => ['nullable', 'boolean'],
        ]);

        $subscription = $this->subscriptions->subscribe($request->user(), Plan::findOrFail($data['planId']), $request->boolean('autoRenew', true));

        return response()->json(
            ['subscription' => $subscription ? new SubscriptionResource($subscription->load('plan')) : null],
            $subscription ? 201 : 200,
        );
    }

    /**
     * Stop renewing.
     *
     * The plan keeps working until the end of the period already paid.
     */
    public function cancel(Request $request): SubscriptionResource
    {
        return new SubscriptionResource($this->subscriptions->cancel($request->user())->load('plan'));
    }

    /**
     * Resume renewing.
     */
    public function resume(Request $request): SubscriptionResource
    {
        return new SubscriptionResource($this->subscriptions->resume($request->user())->load('plan'));
    }
}
