<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Dispute;
use App\Models\DisputeAppeal;
use App\Models\KycSubmission;
use App\Models\PointTransaction;
use App\Models\Project;
use App\Models\Promotion;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Review;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserMedal;
use App\Models\Withdrawal;
use App\Support\Money;
use App\Support\Sql;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminAnalyticsController extends Controller
{
    /**
     * Reports & analytics.
     *
     * Operating numbers over a period (default 30 days): sign-ups by account type, projects and offers, money (top-ups,
     * withdrawals, platform revenue by source, escrow), disputes and appeals (outcomes and time to settle), identity
     * checks (outcomes and time to review), reviews, paid services, promotions, points and medals, and content reports.
     *
     * @return array{data: array<string, mixed>}
     */
    public function __invoke(Request $request): array
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = $request->date('from') ?? now()->subDays(29)->startOfDay();
        $to = ($request->date('to') ?? now())->copy()->endOfDay();
        $in = fn ($query, string $column = 'created_at') => $query->whereBetween($column, [$from, $to]);
        $count = fn ($query, string $column = 'status') => $query->selectRaw("{$column} as k, count(*) as total")->groupBy('k')->pluck('total', 'k')->map(fn ($n) => (int) $n)->all();
        $hours = fn (string $table, string $start, string $end) => round((float) DB::table($table)->whereBetween($start, [$from, $to])->whereNotNull($end)
            ->selectRaw('avg('.Sql::hoursBetween($start, $end).') as h')->value('h'), 1);

        return ['data' => [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'users' => [
                'signups' => $in(User::query())->count(),
                'byType' => [
                    'client' => $in(User::query())->whereDoesntHave('profile')->count(),
                    'freelancer' => $in(User::query())->whereHas('profile', fn ($q) => $q->where('type', 'freelancer'))->count(),
                    'influencer' => $in(User::query())->whereHas('profile', fn ($q) => $q->where('type', 'influencer'))->count(),
                ],
                'verified' => $in(User::query())->where(fn ($q) => $q->whereNotNull('email_verified_at')->orWhereNotNull('phone_verified_at'))->count(),
                'active' => User::whereBetween('last_seen_at', [$from, $to])->count(),
                'suspended' => $in(User::query(), 'suspended_at')->count(),
            ],
            'projects' => [
                'posted' => $in(Project::where('type', Project::TYPE_PROJECT))->count(),
                'influencerRequests' => $in(Project::where('type', Project::TYPE_INFLUENCER_REQUEST))->count(),
                'byStatus' => $count($in(Project::query())),
                'proposals' => $in(Proposal::query())->count(),
                'proposalsByStatus' => $count($in(Proposal::query())),
                /** Accepted offers out of all offers sent in the period, in percent. */
                'acceptanceRate' => ($n = $in(Proposal::query())->count()) ? round($in(Proposal::where('status', 'accepted'))->count() / $n * 100, 1) : 0,
            ],
            'money' => $this->money($from, $to),
            'disputes' => [
                'opened' => $in(Dispute::query())->count(),
                'byReason' => $count($in(Dispute::query()), 'reason'),
                'byResolution' => $count($in(Dispute::whereNotNull('resolution'), 'resolved_at'), 'resolution'),
                'avgHoursToResolve' => $hours('disputes', 'created_at', 'resolved_at'),
                'appeals' => $count($in(DisputeAppeal::query())),
            ],
            'kyc' => [
                'submitted' => $in(KycSubmission::query())->count(),
                'byStatus' => $count($in(KycSubmission::query())),
                'avgHoursToReview' => $hours('kyc_submissions', 'created_at', 'reviewed_at'),
            ],
            'reviews' => [
                'count' => $in(Review::query())->count(),
                'average' => round((float) $in(Review::query())->avg('rating'), 2),
                'stars' => $count($in(Review::query()), 'rating'),
                'hidden' => $in(Review::withoutGlobalScope('visible')->whereNotNull('hidden_at'), 'hidden_at')->count(),
            ],
            'services' => [
                'newSubscriptions' => $in(Subscription::query())->count(),
                'activeSubscriptions' => Subscription::whereIn('status', [Subscription::ACTIVE, Subscription::PAST_DUE])->count(),
                'byPlan' => Subscription::join('plans', 'plans.id', '=', 'subscriptions.plan_id')->whereBetween('subscriptions.created_at', [$from, $to])
                    ->selectRaw('plans.name_en as plan, count(*) as total')->groupBy('plans.name_en')->pluck('total', 'plan')->map(fn ($n) => (int) $n)->all(),
                'promotions' => $in(Promotion::query())->count(),
                'promotionsByKind' => $count($in(Promotion::query()), 'kind'),
                'promotionRevenue' => $in(Promotion::query())->selectRaw('currency, sum(price_cents) as total')->groupBy('currency')->pluck('total', 'currency')
                    ->map(fn ($c) => Money::fromCents((int) $c))->all(),
            ],
            'rewards' => [
                'pointsEarned' => (int) $in(PointTransaction::where('amount', '>', 0))->sum('amount'),
                'pointsSpent' => (int) -$in(PointTransaction::where('amount', '<', 0))->sum('amount'),
                'medalsAwarded' => $in(UserMedal::query(), 'awarded_at')->count(),
            ],
            'moderation' => [
                'reports' => $in(Report::query())->count(),
                'byTarget' => $count($in(Report::query()), 'target_type'),
                'byStatus' => $count($in(Report::query())),
            ],
        ]];
    }

    /**
     * Revenue by source, top-ups and paid withdrawals per currency in the period, and what escrow holds now.
     *
     * @return list<array<string, mixed>>
     */
    private function money($from, $to): array
    {
        $flows = DB::table('ledger_entries')->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->join('ledger_transactions', 'ledger_transactions.id', '=', 'ledger_entries.transaction_id')
            ->whereBetween('ledger_entries.created_at', [$from, $to]);

        $revenue = (clone $flows)->where('accounts.type', Account::REVENUE)
            ->selectRaw('accounts.currency as currency, ledger_transactions.type as source, sum(ledger_entries.amount_cents) as total')
            ->groupBy('accounts.currency', 'ledger_transactions.type')->get()->groupBy('currency');
        $byType = fn (string $type) => (clone $flows)->where('ledger_transactions.type', $type)->where('accounts.type', Account::WALLET)
            ->selectRaw('accounts.currency as currency, sum(abs(ledger_entries.amount_cents)) as total')->groupBy('accounts.currency')->pluck('total', 'currency');
        $deposits = $byType('deposit');
        $withdrawals = Withdrawal::where('status', Withdrawal::PAID)->whereBetween('reviewed_at', [$from, $to])
            ->selectRaw('currency, sum(amount_cents) as total')->groupBy('currency')->pluck('total', 'currency');
        $escrow = Account::where('type', Account::ESCROW)->pluck('balance_cents', 'currency');

        return collect([...$revenue->keys(), ...$deposits->keys(), ...$withdrawals->keys(), ...$escrow->keys()])->unique()->values()->map(fn ($currency) => [
            'currency' => $currency,
            'deposits' => Money::fromCents((int) ($deposits[$currency] ?? 0)),
            'withdrawals' => Money::fromCents((int) ($withdrawals[$currency] ?? 0)),
            'revenue' => Money::fromCents((int) ($revenue[$currency] ?? collect())->sum('total')),
            /** Revenue by where it came from: milestone fees, promotions, subscriptions, withdrawal fees... */
            'revenueBySource' => ($revenue[$currency] ?? collect())->mapWithKeys(fn ($row) => [$row->source => Money::fromCents((int) $row->total)])->all(),
            'inEscrowNow' => Money::fromCents((int) ($escrow[$currency] ?? 0)),
        ])->all();
    }
}
