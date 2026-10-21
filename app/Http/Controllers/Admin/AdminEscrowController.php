<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Escrow;
use App\Models\EscrowPayee;
use App\Models\LedgerTransaction;
use App\Models\Milestone;
use App\Models\Project;
use App\Notifications\ActivityNotification;
use App\Services\Payments\EscrowService;
use App\Support\LikeSearch;
use App\Support\Money;
use App\Support\Paged;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminEscrowController extends Controller
{
    public function __construct(private readonly EscrowService $escrows) {}

    /**
     * Contracts.
     *
     * Every payment protection (escrow): who pays whom, the total, what is held right now, released and refunded, and the
     * state of its milestones. Newest first.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>, totals: list<array{currency: string, held: float}>}
     */
    public function index(Request $request): array
    {
        $request->validate([
            /** `awaiting_funding`, `active`, `completed` or `cancelled`. */
            'status' => ['nullable', Rule::in([Escrow::AWAITING_FUNDING, Escrow::ACTIVE, Escrow::COMPLETED, Escrow::CANCELLED])],
            /** `1` for contracts holding money right now. */
            'holding' => ['nullable', 'boolean'],
            /** Search the project title. */
            'q' => ['nullable', 'string', 'max:100'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Escrow::with(['project', 'client', 'milestones', 'payees.user'])
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->boolean('holding'), fn ($q) => $q->whereHas('milestones', fn ($m) => $m->whereIn('status', Milestone::HOLDING)))
            ->when($request->input('q'), fn ($q, $term) => $q->whereHas('project', fn ($p) => LikeSearch::apply($p, ['title'], $term)))
            ->latest('id')->paginate($request->integer('perPage', 20));

        return Paged::of($page, fn (Escrow $e) => self::present($e), [
            /** Money held in escrow right now, per currency. */
            'totals' => Milestone::join('escrows', 'escrows.id', '=', 'milestones.escrow_id')
                ->whereIn('milestones.status', Milestone::HOLDING)
                ->selectRaw('escrows.currency as currency, sum(milestones.amount_cents) as held')->groupBy('escrows.currency')->get()
                ->map(fn ($row) => ['currency' => $row->currency, 'held' => Money::fromCents((int) $row->held)])->all(),
        ]);
    }

    /**
     * Show a contract.
     *
     * With every milestone (requests for changes and delivery notes included), the payout split and the money movements.
     *
     * @return array{data: array<string, mixed>}
     */
    public function show(int $escrow): array
    {
        $escrow = Escrow::with(['project', 'client', 'milestones', 'payees.user'])->findOrFail($escrow);

        return ['data' => [
            ...self::present($escrow, true),
            'transactions' => LedgerTransaction::where(fn ($q) => $q
                ->where(fn ($q) => $q->where('reference_type', Milestone::class)->whereIn('reference_id', $escrow->milestones->pluck('id')))
                ->orWhere(fn ($q) => $q->where('reference_type', Escrow::class)->where('reference_id', $escrow->id)))
                ->latest('id')->get()->map(fn (LedgerTransaction $t) => [
                    'id' => $t->id, 'type' => $t->type, 'description' => $t->description, 'meta' => $t->meta, 'createdAt' => $t->created_at?->toIso8601String(),
                ])->all(),
        ]];
    }

    /**
     * Cancel a contract.
     *
     * Ends the contract from the admin side (fraud, an agreement both sides asked to undo): every milestone paid in and not
     * disputed goes back to the client, unpaid milestones are dropped, the escrow is `cancelled` and the project closed.
     * Settle open disputes first (`dispute_open`).
     *
     * @return array{data: array<string, mixed>}
     */
    public function cancel(Request $request, int $escrow): array
    {
        $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $escrow = Escrow::with(['milestones', 'project', 'client', 'payees.user'])->findOrFail($escrow);

        if (in_array($escrow->status, [Escrow::COMPLETED, Escrow::CANCELLED], true)) {
            throw new ApiException(__('admin.escrow_closed'), 'escrow_closed');
        }

        if ($escrow->milestones->contains('status', Milestone::DISPUTED)) {
            throw new ApiException(__('admin.escrow_disputed'), 'dispute_open');
        }

        DB::transaction(function () use ($escrow, $request) {
            $escrow->milestones()->where('status', Milestone::PENDING)->delete();

            foreach ($escrow->milestones()->whereIn('status', [Milestone::FUNDED, Milestone::SUBMITTED])->lockForUpdate()->get() as $milestone) {
                $this->escrows->settle($milestone, $escrow, $milestone->amount_cents, $request->user(), 'escrow_refund');
            }

            $escrow->update(['status' => Escrow::CANCELLED]);
            $escrow->project->update(['status' => $escrow->load('milestones')->releasedCents() > 0 ? Project::STATUS_COMPLETED : Project::STATUS_CLOSED]);
        });

        $labels = ['projectId' => $escrow->project_id, 'project' => $escrow->project->title, 'reason' => $request->input('reason')];
        foreach ([$escrow->client, ...$escrow->payees->pluck('user')] as $party) {
            $party?->notify(new ActivityNotification('escrow.cancelled_by_admin', null, $labels));
        }

        return ['data' => self::present($escrow->refresh()->load(['project', 'client', 'milestones', 'payees.user']), true)];
    }

    /**
     * An escrow for the admin screens with its client and payees; the detail view adds the milestones.
     *
     * @return array<string, mixed>
     */
    public static function present(Escrow $escrow, bool $detail = false): array
    {
        $money = fn (int $cents) => Money::fromCents($cents);
        $time = fn ($value) => $value?->toIso8601String();

        return [
            'id' => $escrow->id,
            'project' => $escrow->project ? ['id' => $escrow->project->id, 'title' => $escrow->project->title, 'type' => $escrow->project->type, 'status' => $escrow->project->status] : null,
            'client' => Paged::user($escrow->client),
            'payees' => $escrow->payees->map(fn (EscrowPayee $p) => [
                'user' => Paged::user($p->user), 'sharePercent' => $p->share_bp / 100, 'isLead' => (bool) $p->is_lead,
                /** `pending`, `approved` or `objected` (with `splitNote`). */
                'splitStatus' => $p->split_status, 'splitNote' => $p->split_note,
            ])->all(),
            'currency' => $escrow->currency,
            'total' => $money($escrow->total_cents),
            'held' => $money($escrow->heldCents()),
            'released' => $money($escrow->releasedCents()),
            'refunded' => $money($escrow->refundedCents()),
            /** `awaiting_funding`, `active`, `completed` or `cancelled`. */
            'status' => $escrow->status,
            /** Milestones per status. */
            'milestoneStatuses' => $escrow->milestones->countBy('status')->all(),
            'milestones' => $detail ? $escrow->milestones->sortBy('position')->values()->map(fn (Milestone $m) => [
                'id' => $m->id, 'position' => $m->position, 'title' => $m->title, 'amount' => $money($m->amount_cents),
                /** `pending`, `funded`, `submitted`, `disputed`, `released` or `refunded`. */
                'status' => $m->status, 'dueDate' => $m->due_date?->toDateString(),
                'fee' => $money($m->fee_cents), 'refunded' => $money($m->refunded_cents),
                'submissionNote' => $m->submission_note,
                /** What the client asked to change before approving. */
                'changesNote' => $m->changes_note,
                'fundedAt' => $time($m->funded_at), 'submittedAt' => $time($m->submitted_at), 'releasedAt' => $time($m->released_at),
            ])->all() : null,
            'createdAt' => $time($escrow->created_at),
        ];
    }
}
