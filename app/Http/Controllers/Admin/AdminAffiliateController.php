<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AffiliateEarning;
use App\Models\User;
use App\Services\AffiliateService;
use Dedoc\Scramble\Attributes\Group;
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
}
