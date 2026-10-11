<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaginatedRequest;
use App\Models\AffiliateEarning;
use App\Models\User;
use App\Services\AffiliateService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

#[Group('Rewards', 'Points, medals, the prize draw and the referral programme.', weight: 12)]
class AffiliateController extends Controller
{
    public function __construct(private readonly AffiliateService $affiliates) {}

    /**
     * My referral programme.
     *
     * The invite code and link, the numbers (link visits, sign-ups, qualified referrals, conversion), earnings per
     * currency, the current and next tier, all tiers, and the last 6 months of earnings for the chart.
     *
     * @return array<string, mixed>
     */
    public function show(Request $request): array
    {
        $user = $request->user();
        $visits = DB::table('affiliate_clicks')->where('user_id', $user->id)->count();
        $signups = User::where('referred_by', $user->id)->count();
        $qualified = $this->affiliates->qualifiedReferrals($user);
        $months = collect(range(5, 0))->map(fn ($ago) => now()->startOfMonth()->subMonths($ago));

        return [
            'code' => $user->referral_code,
            'link' => config('auth_flow.frontend_url').'/signup?ref='.$user->referral_code,
            'stats' => [
                'visits' => $visits,
                'signups' => $signups,
                'qualified' => $qualified,
                /** Sign-ups per 100 link visits. */
                'conversionRate' => $visits ? round($signups / $visits * 100, 1) : 0,
            ],
            'earnings' => AffiliateEarning::where('referrer_id', $user->id)->groupBy('currency')
                ->selectRaw('currency, sum(amount_cents) as cents')->get()
                ->map(fn ($row) => ['currency' => $row->currency, 'amount' => $row->cents / 100])->values(),
            'tier' => $this->affiliates->tierFor($user),
            'nextTier' => $this->affiliates->nextTier($user),
            'tiers' => $this->affiliates->tiers(),
            'monthly' => $months->map(fn ($start) => [
                'month' => $start->format('Y-m'),
                'amount' => AffiliateEarning::where('referrer_id', $user->id)->whereBetween('created_at', [$start, $start->copy()->endOfMonth()])->sum('amount_cents') / 100,
            ])->values(),
        ];
    }

    /**
     * People I invited.
     *
     * Newest first, with whether they qualified (were paid at least once) and what they earned me.
     *
     * @return array<string, mixed>
     */
    public function referrals(PaginatedRequest $request): array
    {
        $page = User::where('referred_by', $request->user()->id)->latest('id')->paginate($request->integer('perPage', 15));
        $earned = AffiliateEarning::where('referrer_id', $request->user()->id)->whereIn('referred_id', collect($page->items())->pluck('id'))
            ->groupBy('referred_id')->selectRaw('referred_id, sum(amount_cents) as cents')->pluck('cents', 'referred_id');

        return [
            'data' => collect($page->items())->map(fn (User $user) => [
                'id' => $user->id,
                'fullName' => $user->full_name,
                'avatarUrl' => $user->avatar_url,
                'joinedAt' => $user->created_at,
                'verified' => $user->isVerified(),
                'qualified' => $earned->has($user->id),
                'earned' => ($earned[$user->id] ?? 0) / 100,
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ];
    }

    /**
     * My referral earnings.
     *
     * Each commission, newest first.
     *
     * @return array<string, mixed>
     */
    public function earnings(PaginatedRequest $request): array
    {
        $page = AffiliateEarning::with('referred')->where('referrer_id', $request->user()->id)->latest('id')->paginate($request->integer('perPage', 15));

        return [
            'data' => collect($page->items())->map(fn (AffiliateEarning $earning) => [
                'id' => $earning->id,
                'referred' => $earning->referred?->full_name,
                'rate' => (float) $earning->rate_percent,
                'amount' => $earning->amount_cents / 100,
                'currency' => $earning->currency,
                'createdAt' => $earning->created_at,
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ];
    }

    /**
     * Count an invite-link visit.
     *
     * Called by the signup page when it opens with `?ref=`. One visit per visitor per day; unknown codes are ignored.
     *
     * @unauthenticated
     *
     * @return array{recorded: bool}
     */
    public function visit(Request $request): array
    {
        $code = strtoupper((string) $request->validate(['code' => ['required', 'string', 'max:12']])['code']);
        $owner = User::where('referral_code', $code)->value('id');
        $hash = hash('sha256', $request->ip().'|'.$request->userAgent().'|'.config('app.key'));

        $seen = $owner && DB::table('affiliate_clicks')->where(['user_id' => $owner, 'ip_hash' => $hash])->where('created_at', '>=', now()->startOfDay())->exists();
        if ($owner && ! $seen) {
            DB::table('affiliate_clicks')->insert(['user_id' => $owner, 'ip_hash' => $hash, 'created_at' => now()]);
        }

        return ['recorded' => $owner && ! $seen];
    }
}
