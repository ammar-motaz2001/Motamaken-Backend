<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AffiliateEarning;
use App\Models\PointTransaction;
use App\Models\User;
use App\Services\AffiliateService;
use App\Services\RewardsService;
use App\Support\Paged;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminAffiliateController extends Controller
{
    /**
     * Affiliate overview.
     *
     * The programme at a glance: how many people invite and were invited, link visits, commissions paid per currency,
     * the top referrers, and the latest commissions.
     *
     * @return array{totals: array{referrers: int, referredUsers: int, qualifiedReferrals: int, visits: int, commissions: list<array{currency: string, amount: float}>}, topReferrers: list<array<string, mixed>>, recent: list<array<string, mixed>>}
     */
    public function __invoke(AffiliateService $affiliates): array
    {
        $top = AffiliateEarning::query()
            ->selectRaw('referrer_id, currency, count(distinct referred_id) as referrals, sum(amount_cents) as earned')
            ->groupBy('referrer_id', 'currency')->orderByDesc('earned')->limit(10)->get();
        $users = User::whereIn('id', $top->pluck('referrer_id'))->get()->keyBy('id');

        return [
            'totals' => [
                'referrers' => User::whereIn('id', User::whereNotNull('referred_by')->select('referred_by'))->count(),
                'referredUsers' => User::whereNotNull('referred_by')->count(),
                /** Invited people who were paid at least once. */
                'qualifiedReferrals' => AffiliateEarning::distinct()->count('referred_id'),
                'visits' => DB::table('affiliate_clicks')->count(),
                'commissions' => AffiliateEarning::selectRaw('currency, sum(amount_cents) as total')->groupBy('currency')->get()
                    ->map(fn ($row) => ['currency' => $row->currency, 'amount' => (int) $row->total / 100])->all(),
            ],
            'topReferrers' => $top->map(fn ($row) => [
                'userId' => $row->referrer_id,
                'fullName' => $users[$row->referrer_id]?->full_name,
                'tier' => $users[$row->referrer_id] ? $affiliates->tierFor($users[$row->referrer_id])['code'] : null,
                'qualifiedReferrals' => (int) $row->referrals,
                'earned' => (int) $row->earned / 100,
                'currency' => $row->currency,
            ])->all(),
            'recent' => AffiliateEarning::with(['referrer', 'referred'])->latest('id')->limit(20)->get()->map(fn ($e) => [
                'id' => $e->id,
                'referrer' => $e->referrer->full_name,
                'referred' => $e->referred->full_name,
                'ratePercent' => $e->rate_percent,
                'amount' => $e->amount_cents / 100,
                'currency' => $e->currency,
                'createdAt' => $e->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /**
     * Suspicious referrals.
     *
     * Invited accounts that look like the inviter's own: signed up from the same address, or signed in from an address
     * the inviter also uses. Revoke the referral points of the ones that are abuse.
     *
     * @return array{data: list<array<string, mixed>>}
     */
    public function suspicious(): array
    {
        $tokenIps = fn (array $ids) => DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $ids)
            ->whereNotNull('ip_address')->select('tokenable_id', 'ip_address')->distinct()->get()->groupBy('tokenable_id')
            ->map(fn ($rows) => $rows->pluck('ip_address')->all());

        $referred = User::with('referrer')->whereNotNull('referred_by')->latest('id')->limit(2000)->get();
        $ips = $tokenIps($referred->pluck('id')->merge($referred->pluck('referred_by'))->unique()->all());
        $revoked = PointTransaction::where('reason', 'admin_adjustment')->where('meta->referralRevoked', true)->pluck('meta')
            ->map(fn ($meta) => $meta['userId'] ?? null)->filter()->all();

        return ['data' => $referred->map(function (User $user) use ($ips) {
            $reasons = [];
            if ($user->registration_ip && $user->registration_ip === $user->referrer?->registration_ip) {
                $reasons[] = 'same_signup_ip';
            }
            if ($shared = array_values(array_intersect($ips[$user->id] ?? [], $ips[$user->referred_by] ?? []))) {
                $reasons[] = 'shared_login_ip';
            }

            return $reasons ? ['user' => Paged::user($user), 'referrer' => Paged::user($user->referrer), 'reasons' => $reasons,
                'sharedIps' => $shared, 'createdAt' => $user->created_at?->toIso8601String()] : null;
        })->filter()->map(fn ($row) => [...$row, 'revoked' => in_array($row['user']['id'], $revoked, true)])->values()->all()];
    }

    /**
     * Revoke a referral.
     *
     * Takes back the points the inviter got for this invited account (never below zero) and unlinks it, so no further
     * commission is paid on it.
     *
     * @return array{success: bool}
     */
    public function revoke(Request $request, int $user, RewardsService $rewards): array
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);
        $invited = User::whereNotNull('referred_by')->findOrFail($user);
        $referrer = User::findOrFail($invited->referred_by);
        $points = (int) PointTransaction::where('user_id', $referrer->id)->where('key', "referral:{$invited->id}")->value('amount');

        DB::transaction(function () use ($invited, $referrer, $points, $data, $request, $rewards) {
            if ($points > 0) {
                $take = min($points, (int) User::whereKey($referrer->id)->value('points'));
                if ($take > 0) {
                    $tx = $rewards->adjust($referrer, -$take, $data['reason'], $request->user());
                    $tx->update(['meta' => [...$tx->meta, 'referralRevoked' => true, 'userId' => $invited->id]]);
                }
            }
            $invited->forceFill(['referred_by' => null])->save();
        });

        return ['success' => true];
    }
}
