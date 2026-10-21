<?php

namespace App\Services;

use App\Models\AffiliateEarning;
use App\Models\Escrow;
use App\Models\Milestone;
use App\Models\PointTransaction;
use App\Models\User;
use App\Notifications\ActivityNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
     * Why an invitation looks like self-referral: the same sign-up IP as the inviter, an IP both signed in from, or
     * the inviter already earning their daily share of referral rewards. Empty when nothing stands out.
     *
     * @param  array<int, list<string>>|null  $loginIps  Sign-in IPs per user id, when already loaded for many users.
     * @return list<string>
     */
    public function suspicion(User $invited, ?array $loginIps = null, bool $countToday = false): array
    {
        $referrer = $invited->referrer;
        if (! $referrer) {
            return [];
        }

        $reasons = [];
        if ($invited->registration_ip && $invited->registration_ip === $referrer->registration_ip) {
            $reasons[] = 'same_signup_ip';
        }
        $loginIps ??= $this->loginIps([$invited->id, $referrer->id]);
        if (array_intersect($loginIps[$invited->id] ?? [], $loginIps[$referrer->id] ?? [])) {
            $reasons[] = 'shared_login_ip';
        }
        if ($countToday && PointTransaction::where('user_id', $referrer->id)->where('reason', 'referral')
            ->where('created_at', '>=', now()->startOfDay())->count() >= (int) config('engagement.referral_daily_cap')) {
            $reasons[] = 'daily_cap';
        }

        return $reasons;
    }

    /**
     * The IP addresses each user has signed in from (their API tokens), by user id.
     *
     * @param  list<int>  $ids
     * @return array<int, list<string>>
     */
    public function loginIps(array $ids): array
    {
        return DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $ids)
            ->whereNotNull('ip_address')->select('tokenable_id', 'ip_address')->distinct()->get()->groupBy('tokenable_id')
            ->map(fn ($rows) => $rows->pluck('ip_address')->all())->all();
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

            // No commission on a held (suspected self-) referral, nor when the inviter is the client paying it.
            if (! $referrer || $referrer->suspended_at || $referrer->id === $payee->user_id || $referrer->id === $escrow->client_id
                || $payee->user->referral_held_at) {
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
