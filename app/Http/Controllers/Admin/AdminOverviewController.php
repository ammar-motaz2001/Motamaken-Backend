<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Category;
use App\Models\ContactMessage;
use App\Models\Deposit;
use App\Models\Dispute;
use App\Models\DisputeAppeal;
use App\Models\KycSubmission;
use App\Models\PointTransaction;
use App\Models\Project;
use App\Models\Promotion;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Subscription;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\UserMedal;
use App\Models\Withdrawal;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminOverviewController extends Controller
{
    /**
     * Dashboard.
     *
     * The numbers of the admin home page: users, projects by status, money per currency, the work queues (withdrawals,
     * identity checks, disputes, reports, contact messages), one row per day for the charts (sign-ups, projects, offers,
     * platform revenue and wallet top-ups per currency), the change against the previous period of the same length,
     * the busiest categories and the latest users and projects.
     *
     * @return array{users: array{total: int, verified: int, newThisWeek: int, suspended: int}, usersByType: array<string, int>, projects: array<string, int>, influencerRequests: array<string, int>, proposals: array<string, int>, engagement: array<string, int>, money: list<array{currency: string, inWallets: float, inEscrow: float, revenue: float, pendingWithdrawals: float}>, queues: array{pendingWithdrawals: int, pendingKyc: int, openDisputes: int, openReports: int, newContactMessages: int, pendingAppeals: int, openTickets: int, pendingDeposits: int}, days: int, series: list<array{date: string, users: int, projects: int, proposals: int, revenue: array<string, float>, deposits: array<string, float>}>, trends: array{users: array{current: int, previous: int}, projects: array{current: int, previous: int}, proposals: array{current: int, previous: int}, revenue: array<string, array{current: float, previous: float}>, deposits: array<string, array{current: float, previous: float}>}, topCategories: list<array{id: int, name: string, projects: int}>, recent: array{users: list<array<string, mixed>>, projects: list<array<string, mixed>>}}
     */
    public function __invoke(Request $request): array
    {
        $request->validate([
            /** Length of the charts' period: 7, 14 (default), 30 or 90 days. */
            'days' => ['nullable', 'integer', Rule::in([7, 14, 30, 90])],
        ]);
        $length = $request->integer('days', 14);
        $since = now()->subDays($length - 1)->startOfDay();
        $previous = $since->copy()->subDays($length);

        return [
            'users' => [
                'total' => User::count(),
                'verified' => User::where(fn ($q) => $q->whereNotNull('email_verified_at')->orWhereNotNull('phone_verified_at'))->count(),
                'newThisWeek' => User::where('created_at', '>=', now()->subDays(7))->count(),
                'suspended' => User::whereNotNull('suspended_at')->count(),
            ],
            /** Accounts per type: `client` (no profile), `freelancer`, `influencer`. */
            'usersByType' => [
                'client' => User::whereDoesntHave('profile')->count(),
                ...collect(['freelancer' => 0, 'influencer' => 0])->merge(
                    DB::table('profiles')->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type'),
                )->map(fn ($n) => (int) $n)->all(),
            ],
            'projects' => $this->byStatus(Project::where('type', Project::TYPE_PROJECT), self::PROJECT_STATUSES),
            /** Influencer requests per status (they share the projects table). */
            'influencerRequests' => $this->byStatus(Project::where('type', Project::TYPE_INFLUENCER_REQUEST), self::PROJECT_STATUSES),
            /** Offers per status. */
            'proposals' => $this->byStatus(Proposal::query(), ['pending', 'accepted', 'declined', 'withdrawn']),
            /** Paid services, promotions and rewards right now and in the chosen period. */
            'engagement' => [
                'activeSubscriptions' => Subscription::whereIn('status', ['active', 'past_due'])->count(),
                'activePromotions' => Promotion::where('ends_at', '>', now())->count(),
                'pointsIssued' => (int) PointTransaction::where('amount', '>', 0)->where('created_at', '>=', $since)->sum('amount'),
                'pointsSpent' => (int) -PointTransaction::where('amount', '<', 0)->where('created_at', '>=', $since)->sum('amount'),
                'medalsAwarded' => UserMedal::where('awarded_at', '>=', $since)->count(),
            ],
            'money' => $this->money(),
            'queues' => [
                'pendingWithdrawals' => Withdrawal::where('status', Withdrawal::PENDING)->count(),
                'pendingKyc' => KycSubmission::where('status', KycSubmission::PENDING)->count(),
                'openDisputes' => Dispute::whereIn('status', Dispute::ACTIVE)->count(),
                'openReports' => Report::where('status', Report::OPEN)->count(),
                'newContactMessages' => ContactMessage::where('status', ContactMessage::NEW)->whereNull('archived_at')->count(),
                'pendingAppeals' => DisputeAppeal::where('status', DisputeAppeal::PENDING)->count(),
                'openTickets' => SupportTicket::where('status', SupportTicket::OPEN)->count(),
                'pendingDeposits' => Deposit::where('status', Deposit::PENDING)->where('created_at', '<', now()->subMinutes(30))->count(),
            ],
            'days' => $length,
            'series' => $this->series($since, $length),
            'trends' => [
                'users' => $this->compare(User::query(), $since, $previous),
                'projects' => $this->compare(Project::query(), $since, $previous),
                'proposals' => $this->compare(Proposal::query(), $since, $previous),
                'revenue' => $this->compareMoney($this->revenueQuery(), 'ledger_entries.created_at', $since, $previous),
                'deposits' => $this->compareMoney($this->depositQuery(), 'paid_at', $since, $previous),
            ],
            'topCategories' => $this->topCategories($since),
            'recent' => [
                'users' => User::latest()->latest('id')->limit(5)->get()->map(fn (User $user) => [
                    'id' => $user->id, 'fullName' => $user->full_name, 'avatarUrl' => $user->avatar_url,
                    'isVerified' => $user->isVerified(), 'createdAt' => $user->created_at?->toIso8601String(),
                ])->all(),
                'projects' => Project::with('user')->latest()->latest('id')->limit(5)->get()->map(fn (Project $project) => [
                    'id' => $project->id, 'title' => $project->title, 'type' => $project->type, 'status' => $project->status,
                    'budget' => ['min' => $project->budget_min, 'max' => $project->budget_max, 'currency' => $project->currency],
                    'owner' => $project->user?->full_name, 'createdAt' => $project->created_at?->toIso8601String(),
                ])->all(),
            ],
        ];
    }

    private const PROJECT_STATUSES = [Project::STATUS_DRAFT, Project::STATUS_OPEN, Project::STATUS_IN_PROGRESS, Project::STATUS_COMPLETED, Project::STATUS_CLOSED];

    /**
     * Counts per status, zero-filled.
     *
     * @param  list<string>  $statuses
     * @return array<string, int>
     */
    private function byStatus(Builder $query, array $statuses): array
    {
        return collect($statuses)->mapWithKeys(fn (string $status) => [$status => 0])
            ->merge($query->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'))
            ->map(fn ($count) => (int) $count)->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function money(): array
    {
        $totals = Account::selectRaw('currency, type, sum(balance_cents) as balance')->groupBy('currency', 'type')->get()->groupBy('currency');
        $pending = Withdrawal::where('status', Withdrawal::PENDING)->get()->groupBy('currency');

        return $totals->map(fn (Collection $rows, string $currency) => [
            'currency' => $currency,
            'inWallets' => (int) $rows->firstWhere('type', Account::WALLET)?->balance / 100,
            'inEscrow' => (int) $rows->firstWhere('type', Account::ESCROW)?->balance / 100,
            'revenue' => (int) $rows->firstWhere('type', Account::REVENUE)?->balance / 100,
            'pendingWithdrawals' => (int) ($pending[$currency] ?? collect())->sum(fn (Withdrawal $w) => $w->amount_cents + $w->fee_cents) / 100,
        ])->values()->all();
    }

    /**
     * One row per day, oldest first, with every count and amount (zero-filled).
     *
     * @return list<array<string, mixed>>
     */
    private function series(Carbon $since, int $length): array
    {
        $daily = fn (Builder $query, string $column = 'created_at') => $query->where($column, '>=', $since)
            ->selectRaw("date({$column}) as day, count(*) as total")->groupBy('day')->pluck('total', 'day');
        $users = $daily(User::query());
        $projects = $daily(Project::query());
        $proposals = $daily(Proposal::query());
        $revenue = $this->revenueQuery()->where('ledger_entries.created_at', '>=', $since)
            ->selectRaw('date(ledger_entries.created_at) as day, accounts.currency as currency, sum(ledger_entries.amount_cents) as total')
            ->groupBy('day', 'accounts.currency')->get()->groupBy('day');
        $deposits = $this->depositQuery()->where('paid_at', '>=', $since)
            ->selectRaw('date(paid_at) as day, currency, sum(amount_cents) as total')->groupBy('day', 'currency')->get()->groupBy('day');

        $amounts = fn (?Collection $rows) => ($rows ?? collect())->mapWithKeys(fn ($row) => [$row->currency => (int) $row->total / 100])->all();

        return collect(range(0, $length - 1))->map(function (int $offset) use ($since, $users, $projects, $proposals, $revenue, $deposits, $amounts) {
            $day = $since->copy()->addDays($offset)->toDateString();

            return [
                'date' => $day,
                'users' => (int) ($users[$day] ?? 0),
                'projects' => (int) ($projects[$day] ?? 0),
                'proposals' => (int) ($proposals[$day] ?? 0),
                'revenue' => (object) $amounts($revenue[$day] ?? null),
                'deposits' => (object) $amounts($deposits[$day] ?? null),
            ];
        })->all();
    }

    /** Money the platform earned: every movement into its revenue accounts (fees, promotions, subscriptions...). */
    private function revenueQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('ledger_entries')->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')->where('accounts.type', Account::REVENUE);
    }

    private function depositQuery(): Builder
    {
        return Deposit::where('status', Deposit::SUCCEEDED);
    }

    /**
     * @return array{current: int, previous: int}
     */
    private function compare(Builder $query, Carbon $since, Carbon $previous): array
    {
        return [
            'current' => (clone $query)->where('created_at', '>=', $since)->count(),
            'previous' => (clone $query)->whereBetween('created_at', [$previous, $since])->count(),
        ];
    }

    /**
     * @return array<string, array{current: float, previous: float}>
     */
    private function compareMoney(Builder|\Illuminate\Database\Query\Builder $query, string $column, Carbon $since, Carbon $previous): array
    {
        $currency = str_starts_with($column, 'ledger_') ? 'accounts.currency' : 'currency';
        $amount = str_starts_with($column, 'ledger_') ? 'ledger_entries.amount_cents' : 'amount_cents';
        $sum = fn ($from, $to) => (clone $query)->whereBetween($column, [$from, $to])
            ->selectRaw("{$currency} as currency, sum({$amount}) as total")->groupBy($currency)->pluck('total', 'currency');
        $current = $sum($since, now());
        $before = $sum($previous, $since);

        return $current->keys()->merge($before->keys())->unique()->sort()->mapWithKeys(fn (string $code) => [$code => [
            'current' => (int) ($current[$code] ?? 0) / 100,
            'previous' => (int) ($before[$code] ?? 0) / 100,
        ]])->all();
    }

    /**
     * @return list<array{id: int, name: string, projects: int}>
     */
    private function topCategories(Carbon $since): array
    {
        $counts = Project::where('created_at', '>=', $since)->selectRaw('category_id, count(*) as total')
            ->groupBy('category_id')->orderByDesc('total')->limit(6)->pluck('total', 'category_id');
        $categories = Category::whereIn('id', $counts->keys())->get()->keyBy('id');

        return $counts->map(fn ($total, $id) => [
            'id' => (int) $id, 'name' => $categories[$id]?->name ?? '—', 'projects' => (int) $total,
        ])->values()->all();
    }
}
