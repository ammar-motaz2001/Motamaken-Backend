<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ContactMessage;
use App\Models\Dispute;
use App\Models\KycSubmission;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use App\Models\Withdrawal;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminOverviewController extends Controller
{
    /**
     * Dashboard.
     *
     * The numbers of the admin home page: users, projects by status, money per currency, the work queues (withdrawals,
     * identity checks, disputes, reports, contact messages) and the last 14 days of sign-ups and new projects for the charts.
     *
     * @return array{users: array{total: int, verified: int, newThisWeek: int, suspended: int}, projects: array<string, int>, money: list<array{currency: string, inWallets: float, inEscrow: float, revenue: float, pendingWithdrawals: float}>, queues: array{pendingWithdrawals: int, pendingKyc: int, openDisputes: int, openReports: int, newContactMessages: int}, series: list<array{date: string, users: int, projects: int}>}
     */
    public function __invoke(): array
    {
        $days = collect(range(13, 0))->map(fn (int $ago) => now()->subDays($ago)->startOfDay());
        $since = $days->first();

        return [
            'users' => [
                'total' => User::count(),
                'verified' => User::where(fn ($q) => $q->whereNotNull('email_verified_at')->orWhereNotNull('phone_verified_at'))->count(),
                'newThisWeek' => User::where('created_at', '>=', now()->subDays(7))->count(),
                'suspended' => User::whereNotNull('suspended_at')->count(),
            ],
            'projects' => collect([Project::STATUS_OPEN, Project::STATUS_IN_PROGRESS, Project::STATUS_COMPLETED, Project::STATUS_CLOSED])
                ->mapWithKeys(fn (string $status) => [$status => 0])
                ->merge(Project::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'))
                ->map(fn ($count) => (int) $count)->all(),
            'money' => $this->money(),
            'queues' => [
                'pendingWithdrawals' => Withdrawal::where('status', Withdrawal::PENDING)->count(),
                'pendingKyc' => KycSubmission::where('status', KycSubmission::PENDING)->count(),
                'openDisputes' => Dispute::whereIn('status', Dispute::ACTIVE)->count(),
                'openReports' => Report::where('status', Report::OPEN)->count(),
                'newContactMessages' => ContactMessage::where('status', ContactMessage::NEW)->count(),
            ],
            'series' => $this->series($days, User::where('created_at', '>=', $since)->pluck('created_at'), Project::where('created_at', '>=', $since)->pluck('created_at')),
        ];
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
     * @param  Collection<int, Carbon>  $days
     * @param  Collection<int, mixed>  $users
     * @param  Collection<int, mixed>  $projects
     * @return list<array{date: string, users: int, projects: int}>
     */
    private function series(Collection $days, Collection $users, Collection $projects): array
    {
        return $days->map(fn ($day) => [
            'date' => $day->toDateString(),
            'users' => $users->filter(fn ($at) => $at->isSameDay($day))->count(),
            'projects' => $projects->filter(fn ($at) => $at->isSameDay($day))->count(),
        ])->all();
    }
}
