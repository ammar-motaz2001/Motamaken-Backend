<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminLog;
use App\Models\ContactMessage;
use App\Models\Deposit;
use App\Models\Dispute;
use App\Models\LedgerTransaction;
use App\Models\PointTransaction;
use App\Models\Project;
use App\Models\Promotion;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Review;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Withdrawal;
use App\Support\Money;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminExportController extends Controller
{
    /** Dataset => the permission needed to export it. */
    public const DATASETS = [
        'overview' => 'overview',
        'users' => 'users.view',
        'projects' => 'marketplace',
        'proposals' => 'marketplace',
        'reviews' => 'marketplace',
        'ledger' => 'finance.view',
        'deposits' => 'finance.view',
        'withdrawals' => 'finance.view',
        'subscriptions' => 'finance.view',
        'promotions' => 'finance.view',
        'disputes' => 'disputes',
        'points' => 'rewards',
        'reports' => 'reports',
        'contact' => 'support',
        'audit' => 'staff',
    ];

    private const LIMIT = 50000;

    /**
     * Export to CSV.
     *
     * Downloads a list as a CSV file that opens in Excel (UTF-8 with BOM, so Arabic shows correctly): `overview` (the
     * dashboard numbers per day), `users`, `projects`, `proposals`, `reviews`, `ledger`, `deposits`, `withdrawals`,
     * `subscriptions`, `promotions`, `disputes`, `points`, `reports`, `contact` or `audit`. Takes the same filters as the
     * matching list screen (status, search, dates...). Up to 50,000 rows. Needs the permission of that screen and
     * `exports`.
     */
    public function __invoke(Request $request, string $dataset): StreamedResponse
    {
        abort_unless(isset(self::DATASETS[$dataset]), 404);
        abort_unless($request->user()->canAdmin(self::DATASETS[$dataset]), 403, __('admin.forbidden'));

        [$headers, $rows] = $this->{$dataset}($request);

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => is_bool($v) ? ($v ? 'yes' : 'no') : (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v), $row));
            }
            fclose($out);
        }, "mutamakin-{$dataset}-".now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Rows of a query, read in chunks so big exports do not fill the memory.
     *
     * @param  callable(mixed): array<int, mixed>  $map
     * @return iterable<array<int, mixed>>
     */
    private function rows(Builder $query, callable $map): iterable
    {
        foreach ($query->lazyById(1000) as $i => $model) {
            if ($i >= self::LIMIT) {
                break;
            }
            yield $map($model);
        }
    }

    /**
     * A date as `Y-m-d H:i` for a CSV cell, or null when it is empty.
     */
    private function time(mixed $value): ?string
    {
        return $value?->format('Y-m-d H:i');
    }

    /**
     * The dashboard numbers per day (signups, projects, offers, revenue and top-ups per currency), from the overview screen.
     */
    private function overview(Request $request): array
    {
        $request->merge(['days' => (int) $request->input('days', 30)]);
        $data = app(AdminOverviewController::class)($request);
        $currencies = collect($data['series'])->flatMap(fn ($d) => [...array_keys((array) $d['revenue']), ...array_keys((array) $d['deposits'])])->unique()->sort()->values();

        return [
            ['date', 'signups', 'projects', 'offers', ...$currencies->map(fn ($c) => "revenue {$c}"), ...$currencies->map(fn ($c) => "top-ups {$c}")],
            collect($data['series'])->map(fn ($d) => [$d['date'], $d['users'], $d['projects'], $d['proposals'],
                ...$currencies->map(fn ($c) => ((array) $d['revenue'])[$c] ?? 0), ...$currencies->map(fn ($c) => ((array) $d['deposits'])[$c] ?? 0)]),
        ];
    }

    /**
     * Users with the filters of the users screen: contact details, account type, identity, points and suspension.
     */
    private function users(Request $request): array
    {
        return [
            ['id', 'name', 'username', 'email', 'phone', 'type', 'identity', 'points', 'staff', 'suspended', 'suspension reason', 'last seen', 'joined'],
            $this->rows(app(AdminUserController::class)->filtered($request)->with('profile'), fn (User $u) => [
                $u->id, $u->full_name, $u->username, $u->email, $u->phone, $u->profile?->type ?? 'client', $u->kyc_status, $u->points,
                (bool) $u->is_admin, $u->isSuspended(), $u->suspension_reason, $this->time($u->last_seen_at), $this->time($u->created_at),
            ]),
        ];
    }

    /**
     * Projects with the filters of the projects screen, with owner, budget and number of offers.
     */
    private function projects(Request $request): array
    {
        return [
            ['id', 'title', 'type', 'status', 'hidden', 'category', 'owner id', 'owner', 'budget min', 'budget max', 'currency', 'offers', 'featured', 'created'],
            $this->rows(app(AdminProjectController::class)->filtered($request)->with(['user', 'category'])->withCount('proposals'), fn (Project $p) => [
                $p->id, $p->title, $p->type, $p->status, $p->hidden_at !== null, $p->category?->name_en, $p->user_id, $p->user?->full_name,
                $p->budget_min, $p->budget_max, $p->currency, $p->proposals_count, (bool) $p->is_featured, $this->time($p->created_at),
            ]),
        ];
    }

    /**
     * Offers with the filters of the offers screen, with their project, sender and team.
     */
    private function proposals(Request $request): array
    {
        return [
            ['id', 'project id', 'project', 'sender id', 'sender', 'team', 'amount', 'currency', 'delivery days', 'status', 'created'],
            $this->rows(app(AdminProposalController::class)->filtered($request)->with(['project', 'user', 'team']), fn (Proposal $p) => [
                $p->id, $p->project_id, $p->project?->title, $p->user_id, $p->user?->full_name, $p->team?->name, (float) $p->amount, $p->currency,
                $p->delivery_days, $p->status, $this->time($p->created_at),
            ]),
        ];
    }

    /**
     * Reviews with the filters of the reviews screen, hidden ones included.
     */
    private function reviews(Request $request): array
    {
        return [
            ['id', 'project id', 'rating', 'comment', 'reviewer', 'reviewee', 'hidden', 'created'],
            $this->rows(app(AdminReviewController::class)->filtered($request)->with(['reviewer', 'reviewee']), fn (Review $r) => [
                $r->id, $r->project_id, $r->rating, $r->comment, $r->reviewer?->full_name, $r->reviewee?->full_name, $r->hidden_at !== null, $this->time($r->created_at),
            ]),
        ];
    }

    /**
     * One row per ledger entry, so a transaction with two entries gives two rows that share its number.
     */
    private function ledger(Request $request): array
    {
        return [
            ['transaction', 'type', 'description', 'account', 'user id', 'currency', 'amount', 'balance after', 'by', 'date'],
            (function () use ($request) {
                /** @var LedgerTransaction $t */
                foreach (app(AdminLedgerController::class)->filtered($request)->with(['entries.account', 'creator'])->lazyById(500) as $i => $t) {
                    if ($i >= self::LIMIT) {
                        break;
                    }
                    foreach ($t->entries as $e) {
                        yield [$t->id, $t->type, $t->description, $e->account->type, $e->account->user_id, $e->account->currency,
                            Money::fromCents($e->amount_cents), Money::fromCents($e->balance_after_cents), $t->creator?->full_name, $this->time($t->created_at)];
                    }
                }
            })(),
        ];
    }

    /**
     * Wallet top-ups with the filters of the deposits screen, with the gateway reference and failure reason.
     */
    private function deposits(Request $request): array
    {
        return [
            ['id', 'user id', 'user', 'amount', 'currency', 'method', 'provider', 'provider reference', 'status', 'failure', 'paid', 'created'],
            $this->rows(app(AdminDepositController::class)->filtered($request)->with('user'), fn (Deposit $d) => [
                $d->id, $d->user_id, $d->user?->full_name, Money::fromCents($d->amount_cents), $d->currency, $d->method, $d->provider, $d->provider_ref,
                $d->status, $d->failure_reason, $this->time($d->paid_at), $this->time($d->created_at),
            ]),
        ];
    }

    /**
     * Withdrawal requests, filtered by status and creation date, with fee, payout reference and reject reason.
     */
    private function withdrawals(Request $request): array
    {
        $query = Withdrawal::with('user')
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('from'), fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($request->input('to'), fn ($q, $d) => $q->where('created_at', '<', now()->parse($d)->addDay()));

        return [
            ['id', 'user id', 'user', 'amount', 'fee', 'currency', 'method', 'status', 'payout reference', 'reject reason', 'reviewed', 'created'],
            $this->rows($query, fn (Withdrawal $w) => [
                $w->id, $w->user_id, $w->user?->full_name, Money::fromCents($w->amount_cents), Money::fromCents($w->fee_cents), $w->currency, $w->method,
                $w->status, $w->payout_reference, $w->reject_reason, $this->time($w->reviewed_at), $this->time($w->created_at),
            ]),
        ];
    }

    /**
     * Subscriptions with the filters of the subscriptions screen, with plan and billing period.
     */
    private function subscriptions(Request $request): array
    {
        return [
            ['id', 'user id', 'user', 'plan', 'status', 'auto renew', 'period start', 'period end', 'cancelled', 'created'],
            $this->rows(app(AdminSubscriptionController::class)->filtered($request)->with(['user', 'plan']), fn (Subscription $s) => [
                $s->id, $s->user_id, $s->user?->full_name, $s->plan?->name_en, $s->status, (bool) $s->auto_renew, $this->time($s->current_period_start),
                $this->time($s->current_period_end), $this->time($s->cancelled_at), $this->time($s->created_at),
            ]),
        ];
    }

    /**
     * Bought promotions (featured profiles and projects) with the filters of the promotions screen.
     */
    private function promotions(Request $request): array
    {
        return [
            ['id', 'user id', 'user', 'kind', 'package', 'project', 'days', 'price', 'currency', 'starts', 'ends'],
            $this->rows(app(AdminPromotionController::class)->filtered($request)->with(['user', 'project']), fn (Promotion $p) => [
                $p->id, $p->user_id, $p->user?->full_name, $p->kind, $p->package, $p->project?->title, $p->days, Money::fromCents((int) $p->price_cents),
                $p->currency, $this->time($p->starts_at), $this->time($p->ends_at),
            ]),
        ];
    }

    /**
     * Disputes, filtered by status, with the milestone amount, both parties and the resolution.
     */
    private function disputes(Request $request): array
    {
        $query = Dispute::with(['opener', 'respondent', 'escrow.project', 'milestone'])
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s));

        return [
            ['case', 'project', 'milestone', 'amount', 'currency', 'opened by', 'against', 'reason', 'status', 'resolution', 'client %', 'opened', 'resolved'],
            $this->rows($query, fn (Dispute $d) => [
                $d->caseNumber(), $d->escrow?->project?->title, $d->milestone?->title, $d->milestone ? Money::fromCents($d->milestone->amount_cents) : null,
                $d->escrow?->currency, $d->opener?->full_name, $d->respondent?->full_name, $d->reason, $d->status, $d->resolution, $d->client_percent,
                $this->time($d->created_at), $this->time($d->resolved_at),
            ]),
        ];
    }

    /**
     * Point transactions with the filters of the rewards screen; `manual` marks the ones an admin gave or took.
     */
    private function points(Request $request): array
    {
        return [
            ['id', 'user id', 'user', 'amount', 'reason', 'manual', 'date'],
            $this->rows(app(AdminRewardsController::class)->filtered($request)->with('user'), fn (PointTransaction $t) => [
                $t->id, $t->user_id, $t->user?->full_name, $t->amount, $t->reason, $t->created_by !== null, $this->time($t->created_at),
            ]),
        ];
    }

    /**
     * Content reports, filtered by status and target type, with what was done about them.
     */
    private function reports(Request $request): array
    {
        $query = Report::with('reporter')
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('targetType'), fn ($q, $t) => $q->where('target_type', $t));

        return [
            ['id', 'target', 'target id', 'reason', 'details', 'priority', 'source', 'status', 'action', 'note', 'reporter', 'created', 'reviewed'],
            $this->rows($query, fn (Report $r) => [
                $r->id, $r->target_type, $r->target_id, $r->reason, $r->details, $r->priority, $r->source, $r->status, $r->action_taken,
                $r->resolution_note, $r->reporter?->full_name, $this->time($r->created_at), $this->time($r->reviewed_at),
            ]),
        ];
    }

    /**
     * Contact form messages with the filters of the inbox screen, with the admin note and reply.
     */
    private function contact(Request $request): array
    {
        return [
            ['id', 'name', 'email', 'topic', 'subject', 'message', 'status', 'note', 'reply', 'archived', 'created'],
            $this->rows(app(AdminContactController::class)->filtered($request), fn (ContactMessage $m) => [
                $m->id, $m->name, $m->email, $m->topic, $m->subject, $m->message, $m->status, $m->admin_note, $m->reply, $m->archived_at !== null, $this->time($m->created_at),
            ]),
        ];
    }

    /**
     * The admin audit log, filtered by admin, action prefix and date.
     */
    private function audit(Request $request): array
    {
        $query = AdminLog::with('admin')
            ->when($request->input('adminId'), fn ($q, $id) => $q->where('admin_id', $id))
            ->when($request->input('action'), fn ($q, $a) => $q->where('action', 'like', addcslashes($a, '%_\\').'%'))
            ->when($request->input('from'), fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($request->input('to'), fn ($q, $d) => $q->where('created_at', '<', now()->parse($d)->addDay()));

        return [
            ['id', 'admin', 'action', 'subject', 'subject id', 'changes', 'ip', 'date'],
            $this->rows($query, fn (AdminLog $l) => [$l->id, $l->admin?->full_name, $l->action, $l->subject_type, $l->subject_id, $l->changes, $l->ip, $this->time($l->created_at)]),
        ];
    }
}
