<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DisputeAppeal;
use App\Services\AppealService;
use App\Support\Money;
use App\Support\Paged;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminAppealController extends Controller
{
    public function __construct(private readonly AppealService $appeals) {}

    /**
     * Appeals.
     *
     * Requests for a second look at settled disputes, with the first decision. Pending ones oldest first (the queue).
     * Open the case with `GET /disputes/{id}` for the timeline and the new evidence.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>, counts: array<string, int>}
     */
    public function index(Request $request): array
    {
        $request->validate([
            /** `pending` (default), `upheld` or `overturned`. */
            'status' => ['nullable', Rule::in([DisputeAppeal::PENDING, DisputeAppeal::UPHELD, DisputeAppeal::OVERTURNED])],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $status = $request->input('status', DisputeAppeal::PENDING);

        $page = DisputeAppeal::with(['user', 'decider', 'dispute.escrow.project', 'dispute.milestone'])
            ->where('status', $status)->orderBy('id', $status === DisputeAppeal::PENDING ? 'asc' : 'desc')
            ->paginate($request->integer('perPage', 20));

        return Paged::of($page, fn (DisputeAppeal $a) => self::present($a), [
            'counts' => DisputeAppeal::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n)->all(),
        ]);
    }

    /**
     * Decide an appeal.
     *
     * `upheld` keeps the first decision. `overturned` changes it: send `amount` and `to` (`client` or `freelancer`) to move
     * that much between the two wallets, recorded in the ledger under your name. Both sides are notified.
     *
     * @return array{data: array<string, mixed>}
     */
    public function decide(Request $request, int $appeal): array
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in([DisputeAppeal::UPHELD, DisputeAppeal::OVERTURNED])],
            /** Shown to both sides. */
            'note' => ['required', 'string', 'min:5', 'max:2000'],
            /** The correction, up to the milestone amount. */
            'amount' => ['nullable', 'required_if:decision,overturned', 'numeric', 'min:0', 'decimal:0,2'],
            'to' => ['nullable', 'required_with:amount', Rule::in(['client', 'freelancer'])],
        ]);

        $appeal = $this->appeals->decide(
            DisputeAppeal::findOrFail($appeal), $request->user(), $data['decision'], $data['note'],
            Money::toCents($data['amount'] ?? 0), $data['to'] ?? null,
        );

        return ['data' => self::present($appeal->load(['user', 'decider', 'dispute.escrow.project', 'dispute.milestone']))];
    }

    /**
     * An appeal for the admin list, with its dispute summary.
     *
     * @return array<string, mixed>
     */
    public static function present(DisputeAppeal $a): array
    {
        $d = $a->dispute;

        return [
            'id' => $a->id,
            'user' => Paged::user($a->user),
            'reason' => $a->reason,
            'status' => $a->status,
            'dispute' => $d ? [
                'id' => $d->id, 'caseNumber' => $d->caseNumber(), 'resolution' => $d->resolution, 'clientPercent' => $d->client_percent,
                'resolutionNote' => $d->resolution_note, 'resolvedAt' => $d->resolved_at?->toIso8601String(),
                'project' => $d->escrow?->project ? ['id' => $d->escrow->project->id, 'title' => $d->escrow->project->title] : null,
                'milestone' => $d->milestone ? ['title' => $d->milestone->title, 'amount' => Money::fromCents($d->milestone->amount_cents)] : null,
                'currency' => $d->escrow?->currency,
            ] : null,
            'decisionNote' => $a->decision_note,
            'adjustment' => $a->adjustment_cents ? Money::fromCents($a->adjustment_cents) : null,
            'adjustmentTo' => $a->adjustment_to,
            'decidedBy' => Paged::user($a->decider),
            'decidedAt' => $a->decided_at?->toIso8601String(),
            'createdAt' => $a->created_at?->toIso8601String(),
        ];
    }
}
