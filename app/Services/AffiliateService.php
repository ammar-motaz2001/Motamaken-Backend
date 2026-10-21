<?php

namespace App\Services;

use App\Models\AffiliateEarning;
use App\Models\Escrow;
use App\Models\Milestone;
use App\Models\User;
use App\Notifications\ActivityNotification;
use Illuminate\Support\Collection;

/**
 * The referral programme. When someone who was invited earns money, the person who invited them gets a share of the platform
 * fee on that payment, taken out of the platform's cut (the freelancer's pay does not change). The share depends on the
 * referrer's tier, which depends on how many of their invitees have been paid at least once.
 */
class AffiliateService
{
    /**
     * The referral tiers from the config, from the lowest to the highest.
     *
     * @return Collection<int, array{code: string, min: int, percent: int}>
     */
    public function tiers(): Collection
    {
        return collect(config('marketplace.affiliate.tiers'))->sortBy('min')->values();
    }

    /**
     * How many of the people the user invited have been paid at least once.
     */
    public function qualifiedReferrals(User $referrer): int
    {
        return AffiliateEarning::where('referrer_id', $referrer->id)->distinct()->count('referred_id');
    }

    /**
     * The highest tier the referrer has reached with their qualified referrals.
     *
     * @return array{code: string, min: int, percent: int}
     */
    public function tierFor(User $referrer): array
    {
        $qualified = $this->qualifiedReferrals($referrer);

        return $this->tiers()->last(fn (array $tier) => $qualified >= $tier['min']);
    }

    /**
     * The next tier up and how many more qualified referrals it takes, or null at the top.
     *
     * @return array{code: string, percent: int, referralsNeeded: int}|null
     */
    public function nextTier(User $referrer): ?array
    {
        $qualified = $this->qualifiedReferrals($referrer);
        $next = $this->tiers()->first(fn (array $tier) => $tier['min'] > $qualified);

        return $next ? ['code' => $next['code'], 'percent' => $next['percent'], 'referralsNeeded' => $next['min'] - $qualified] : null;
    }

    /**
     * Work out who earns what from one payment, before the money moves.
     *
     * @param  array<int, int>  $feeParts  Each payee's part of the platform fee: user id => cents.
     * @return list<array{referrer: User, referred: User, cents: int, rate: float}>
     */
    public function commissionsFor(Escrow $escrow, array $feeParts): array
    {
        $commissions = [];

        foreach ($escrow->payees as $payee) {
            $referrer = $payee->user->referred_by ? User::find($payee->user->referred_by) : null;

            if (! $referrer || $referrer->suspended_at || $referrer->id === $payee->user_id) {
                continue;
            }

            $rate = (float) $this->tierFor($referrer)['percent'];

            $commissions[] = [
                'referrer' => $referrer, 'referred' => $payee->user, 'rate' => $rate,
                'cents' => intdiv(($feeParts[$payee->user_id] ?? 0) * (int) round($rate * 100), 10000),
            ];
        }

        return $commissions;
    }

    /**
     * Keep the books of what was paid out, and tell the referrers. Run in the same transaction as the payment.
     *
     * @param  list<array{referrer: User, referred: User, cents: int, rate: float}>  $commissions
     */
    public function record(Milestone $milestone, string $currency, array $commissions): void
    {
        foreach ($commissions as $commission) {
            AffiliateEarning::create([
                'referrer_id' => $commission['referrer']->id, 'referred_id' => $commission['referred']->id,
                'milestone_id' => $milestone->id, 'rate_percent' => $commission['rate'],
                'amount_cents' => $commission['cents'], 'currency' => $currency,
            ]);

            if ($commission['cents'] > 0) {
                $commission['referrer']->notify(new ActivityNotification('affiliate.commission', $commission['referred'], [
                    'amount' => $commission['cents'] / 100, 'currency' => $currency,
                ]));
            }
        }
    }
}
