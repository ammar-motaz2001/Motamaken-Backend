<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Account;
use App\Models\Plan;
use App\Models\Proposal;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Services\Payments\LedgerService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Monthly plans paid from the wallet. A plan changes how many offers a user can send, how much the platform keeps of
 * what they earn, and how much a promotion costs them.
 */
class SubscriptionService
{
    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * The plan whose benefits the user has right now, or null for the free default.
     */
    public function planOf(User $user): ?Plan
    {
        return $user->subscription()->with('plan')->first()?->plan;
    }

    /**
     * The platform's percentage on what the user earns: the site setting, or their plan's rate when that is lower.
     */
    public function feePercentFor(?User $earner): float
    {
        $site = (float) Setting::get('fees.site_percent');
        $plan = $earner ? $this->planOf($earner) : null;

        return isset($plan?->features['fee_percent']) ? min($site, (float) $plan->features['fee_percent']) : $site;
    }

    /**
     * Offers the user may send per month, or null for no limit.
     */
    public function monthlyOfferLimit(User $user): ?int
    {
        $plan = $this->planOf($user);

        if (! $plan) {
            return config('marketplace.subscriptions.free_monthly_offers');
        }

        return array_key_exists('monthly_offers', $plan->features)
            ? $plan->features['monthly_offers']
            : config('marketplace.subscriptions.free_monthly_offers');
    }

    /**
     * How many offers the user has sent since the start of the calendar month.
     */
    public function offersSentThisMonth(User $user): int
    {
        return Proposal::where('user_id', $user->id)->where('created_at', '>=', now()->startOfMonth())->count();
    }

    /**
     * Refuse a new offer once the monthly limit of the user's plan is reached, hinting that an upgrade lifts it.
     */
    public function assertCanSendOffer(User $user): void
    {
        $limit = $this->monthlyOfferLimit($user);

        if ($limit !== null && $this->offersSentThisMonth($user) >= $limit) {
            throw new ApiException(__('subscriptions.offer_limit', ['limit' => $limit]), 'offer_limit_reached', 422, ['limit' => $limit, 'upgrade' => true]);
        }
    }

    /**
     * Start a plan now, paying its price from the wallet. Replaces the current plan (no refund for the unused time).
     */
    public function subscribe(User $user, Plan $plan, bool $autoRenew): ?Subscription
    {
        if (! $plan->is_active) {
            throw new ApiException(__('subscriptions.plan_unavailable'), 'plan_unavailable');
        }

        $current = $user->subscription()->first();

        if ($plan->isFree()) {
            // Choosing the free plan lets the paid one run out instead of renewing.
            $current?->update(['auto_renew' => false, 'cancelled_at' => now()]);

            return null;
        }

        if ($current?->plan_id === $plan->id) {
            throw new ApiException(__('subscriptions.already_subscribed'), 'already_subscribed');
        }

        return DB::transaction(function () use ($user, $plan, $autoRenew, $current) {
            $start = now();

            $subscription = Subscription::create([
                'user_id' => $user->id, 'plan_id' => $plan->id, 'auto_renew' => $autoRenew,
                'current_period_start' => $start, 'current_period_end' => $start->copy()->addMonth(),
            ]);

            $this->charge($user, $plan, $subscription, $start->timestamp);
            $current?->update(['status' => Subscription::CANCELLED, 'cancelled_at' => now()]);

            $user->notify(new ActivityNotification('subscription.started', null, [
                'plan' => $plan->name, 'subscriptionId' => $subscription->id, 'until' => $subscription->current_period_end->toDateString(),
            ]));

            return $subscription;
        });
    }

    /**
     * Turn off renewal: the plan keeps working until the paid period ends, with no refund.
     */
    public function cancel(User $user): Subscription
    {
        $subscription = $user->subscription()->first() ?? throw new ApiException(__('subscriptions.none'), 'no_subscription');
        $subscription->update(['auto_renew' => false, 'cancelled_at' => now()]);

        return $subscription;
    }

    /**
     * Turn renewal back on for a subscription that was cancelled but has not run out yet.
     */
    public function resume(User $user): Subscription
    {
        $subscription = $user->subscription()->first() ?? throw new ApiException(__('subscriptions.none'), 'no_subscription');
        $subscription->update(['auto_renew' => true, 'cancelled_at' => null]);

        return $subscription;
    }

    /**
     * Charge the renewals that are due and expire what cannot be renewed. Run daily by the scheduler.
     *
     * @return array{renewed: int, failed: int, expired: int}
     */
    public function renewDue(): array
    {
        $result = ['renewed' => 0, 'failed' => 0, 'expired' => 0];
        $due = Subscription::with(['plan', 'user'])->whereIn('status', [Subscription::ACTIVE, Subscription::PAST_DUE])
            ->where('current_period_end', '<=', now())->get();

        foreach ($due as $subscription) {
            $grace = config('marketplace.subscriptions.grace_days');

            if (! $subscription->auto_renew || $subscription->current_period_end->copy()->addDays($grace)->isPast()) {
                $subscription->update(['status' => Subscription::EXPIRED]);
                $subscription->user->notify(new ActivityNotification('subscription.expired', null, ['plan' => $subscription->plan->name]));
                $result['expired']++;

                continue;
            }

            try {
                DB::transaction(function () use ($subscription) {
                    $start = $subscription->current_period_end;
                    $this->charge($subscription->user, $subscription->plan, $subscription, $start->timestamp);
                    $subscription->update([
                        'status' => Subscription::ACTIVE, 'current_period_start' => $start, 'current_period_end' => $start->copy()->addMonth(),
                    ]);
                });
                $subscription->user->notify(new ActivityNotification('subscription.renewed', null, [
                    'plan' => $subscription->plan->name, 'until' => $subscription->current_period_end->toDateString(),
                ]));
                $result['renewed']++;
            } catch (ApiException) {
                if ($subscription->status !== Subscription::PAST_DUE) {
                    $subscription->update(['status' => Subscription::PAST_DUE]);
                    $subscription->user->notify(new ActivityNotification('subscription.payment_failed', null, [
                        'plan' => $subscription->plan->name, 'days' => $grace,
                    ]));
                }
                $result['failed']++;
            }
        }

        return $result;
    }

    /**
     * Take one month's price from the user's wallet into revenue. The key includes the period start, so a period is
     * never charged twice.
     */
    private function charge(User $user, Plan $plan, Subscription $subscription, int $periodStart): void
    {
        $this->ledger->post('subscription', [
            [$this->ledger->wallet($user, $plan->currency), -$plan->price_cents],
            [$this->ledger->system(Account::REVENUE, $plan->currency), $plan->price_cents],
        ], [
            'reference' => $subscription, 'key' => "subscription:{$subscription->id}:{$periodStart}",
            'meta' => ['plan' => $plan->code, 'amount' => Money::fromCents($plan->price_cents)],
        ]);
    }
}
