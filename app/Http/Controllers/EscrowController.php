<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateEscrowRequest;
use App\Http\Requests\PaginatedRequest;
use App\Http\Resources\EscrowResource;
use App\Models\Escrow;
use App\Models\Project;
use App\Services\Payments\EscrowService;
use App\Support\Money;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

#[Group('Escrow & milestones', 'Payment protection: the client pays milestone by milestone, the freelancer delivers, the client approves.', weight: 7)]
class EscrowController extends Controller
{
    private const WITH = ['milestones', 'payees.user', 'client', 'project'];

    public function __construct(private readonly EscrowService $escrows) {}

    /**
     * Set up payment protection.
     *
     * Client only, once an offer is accepted. Split the agreed price into milestones; their amounts must add up to the
     * offer (`milestones_total`). Nothing is charged yet: fund the milestones next.
     */
    public function store(CreateEscrowRequest $request, int $project): JsonResponse
    {
        $project = Project::findOrFail($project);
        Gate::authorize('manage', $project);

        $escrow = $this->escrows->create($project, $request->user(), $request->input('milestones'));

        return (new EscrowResource($escrow->load(self::WITH)))->response()->setStatusCode(201);
    }

    /**
     * Show a project's escrow.
     *
     * Visible to the client and to the people being paid.
     */
    public function show(Request $request, int $project): EscrowResource
    {
        $escrow = Escrow::with(self::WITH)->where('project_id', $project)->firstOrFail();
        Gate::authorize('view', $escrow);

        return new EscrowResource($escrow);
    }

    /**
     * Fund every waiting milestone.
     *
     * Client only. All or nothing: if the wallet cannot cover everything, nothing is charged (`insufficient_funds`).
     */
    public function fund(Request $request, int $project): EscrowResource
    {
        $escrow = Escrow::with(self::WITH)->where('project_id', $project)->firstOrFail();
        Gate::authorize('client', $escrow);

        return new EscrowResource($this->escrows->fundAll($escrow, $request->user())->load(self::WITH));
    }

    /**
     * Cancel payment protection.
     *
     * Client only, and only while nothing was paid in. Afterwards milestones can be set up again.
     *
     * @return array{success: bool}
     */
    public function cancel(Request $request, int $project): array
    {
        $escrow = Escrow::where('project_id', $project)->firstOrFail();
        Gate::authorize('client', $escrow);

        $this->escrows->cancel($escrow);

        return ['success' => true];
    }

    /**
     * Approve the payout split.
     *
     * Team escrows: every member confirms their share before the client can release any milestone (`split_not_approved`).
     */
    public function approveSplit(Request $request, int $project): EscrowResource
    {
        $escrow = Escrow::with(self::WITH)->where('project_id', $project)->firstOrFail();
        Gate::authorize('view', $escrow);

        return new EscrowResource($this->escrows->answerSplit($escrow, $request->user(), true, null)->load(self::WITH));
    }

    /**
     * Object to the payout split.
     *
     * Blocks releases until the member approves. The client and the team are notified with the reason.
     */
    public function objectSplit(Request $request, int $project): EscrowResource
    {
        $note = $request->validate(['note' => ['required', 'string', 'min:5', 'max:1000']])['note'];
        $escrow = Escrow::with(self::WITH)->where('project_id', $project)->firstOrFail();
        Gate::authorize('view', $escrow);

        return new EscrowResource($this->escrows->answerSplit($escrow, $request->user(), false, $note)->load(self::WITH));
    }

    /**
     * Tip the freelancer.
     *
     * Client only, once at least one milestone was released. Paid from the wallet, split by share, without platform fee.
     */
    public function tip(Request $request, int $project): EscrowResource
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:100000', 'decimal:0,2'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $escrow = Escrow::with(self::WITH)->where('project_id', $project)->firstOrFail();
        Gate::authorize('client', $escrow);

        $this->escrows->tip($escrow, $request->user(), Money::toCents($data['amount']), $data['note'] ?? null);

        return new EscrowResource($escrow->fresh(self::WITH));
    }

    /**
     * My escrows.
     *
     * The escrow overview: every project the user pays for or is paid on, newest first.
     */
    public function mine(PaginatedRequest $request): AnonymousResourceCollection
    {
        $me = $request->user()->id;

        return EscrowResource::collection(
            Escrow::with(self::WITH)
                ->where(fn ($query) => $query->where('client_id', $me)->orWhereHas('payees', fn ($q) => $q->where('user_id', $me)))
                ->latest()->latest('id')
                ->paginate($request->integer('perPage', 15)),
        );
    }
}
