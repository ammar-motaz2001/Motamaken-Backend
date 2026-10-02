<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Account;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Promotion;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Services\Payments\LedgerService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * "Premium Services": pay for days in the homepage "Featured profiles" or "Distinctive projects" slider.
 */
class PromotionService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * What it costs, in cents, after the user's plan discount.
     */
    public function priceCents(User $user, string $kind, string $currency, int $days): int
    {
        $perDay = config("marketplace.promotions.prices.{$kind}.{$currency}")
            ?? throw new ApiException(__('subscriptions.currency_unavailable'), 'currency_unavailable');

        $discount = (float) ($this->subscriptions->planOf($user)?->features['promotion_discount_percent'] ?? 0);

        return (int) round(Money::toCents($perDay) * $days * (100 - $discount) / 100);
    }

    public function buy(User $user, string $kind, ?int $projectId, int $days, string $currency): Promotion
    {
        return DB::transaction(function () use ($user, $kind, $projectId, $days, $currency) {
            $price = $this->priceCents($user, $kind, $currency, $days);

            $target = $kind === Promotion::PROFILE
                ? Profile::where('user_id', $user->id)->first() ?? throw new ApiException(__('proposals.profile_required'), 'profile_required')
                : $this->ownOpenProject($user, $projectId);

            $promotion = Promotion::create([
                'user_id' => $user->id, 'kind' => $kind, 'project_id' => $kind === Promotion::PROJECT ? $target->id : null,
                'days' => $days, 'price_cents' => $price, 'currency' => $currency, 'starts_at' => now(), 'ends_at' => now()->addDays($days),
            ]);

            $this->ledger->post('promotion', [
                [$this->ledger->wallet($user, $currency), -$price],
                [$this->ledger->system(Account::REVENUE, $currency), $price],
            ], ['reference' => $promotion, 'meta' => ['kind' => $kind, 'days' => $days]]);

            // Buying again while still featured adds days to the end.
            $from = $target->is_featured && $target->featured_until?->isFuture() ? $target->featured_until : now();
            $until = $from->copy()->addDays($days);
            $target->update(['is_featured' => true, 'featured_until' => $until]);
            $promotion->update(['ends_at' => $until]);

            $user->notify(new ActivityNotification('promotion.started', null, [
                'kind' => $kind, 'until' => $until->toDateString(), 'promotionId' => $promotion->id,
            ]));

            return $promotion;
        });
    }

    private function ownOpenProject(User $user, ?int $projectId): Project
    {
        $project = Project::where('user_id', $user->id)->find($projectId) ?? throw new ApiException(__('subscriptions.project_not_yours'), 'project_not_yours');

        if (! $project->isOpen()) {
            throw new ApiException(__('proposals.project_not_open'), 'project_not_open');
        }

        return $project;
    }
}
