<?php

namespace App\Http\Controllers;

use App\Models\AgreementChange;
use App\Models\Project;
use App\Services\AgreementService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Escrow & milestones', 'Payment protection: the client pays milestone by milestone, the freelancer delivers, the client approves.', weight: 7)]
class AgreementChangeController extends Controller
{
    public function __construct(private readonly AgreementService $agreements) {}

    /**
     * Agreement change requests.
     *
     * The change and cancel requests on a project, newest first. Only the client and the hired freelancer see them.
     *
     * @return array{data: list<array<string, mixed>>}
     */
    public function index(Request $request, int $project): array
    {
        $project = Project::findOrFail($project);
        abort_unless($this->agreements->isParty($project, $request->user()), 403);

        return ['data' => AgreementChange::with(['requester', 'responder'])->where('project_id', $project->id)->latest('id')->get()
            ->map(fn (AgreementChange $change) => self::present($change, $request->user()->id))->all()];
    }

    /**
     * Request a change or cancellation.
     *
     * `type: change` proposes a new `amount` and/or `deliveryDays` for the accepted offer (the price only while nothing
     * is in escrow); `type: cancel` asks to end the agreement, refunding what is held in escrow to the client. The other
     * side is notified and accepts or declines. One open request per project.
     *
     * @return array{data: array<string, mixed>}
     */
    public function store(Request $request, int $project): array
    {
        $data = $request->validate([
            'type' => ['required', Rule::in([AgreementChange::CHANGE, AgreementChange::CANCEL])],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:1000000', 'decimal:0,2'],
            'deliveryDays' => ['nullable', 'integer', 'min:1', 'max:365'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $change = $this->agreements->request(Project::findOrFail($project), $request->user(), $data);

        return ['data' => self::present($change->load(['requester', 'responder']), $request->user()->id)];
    }

    /**
     * Accept a request.
     *
     * Only the side that did not ask. A change updates the accepted offer; a cancel refunds the escrow to the client and
     * closes the project.
     *
     * @return array{data: array<string, mixed>}
     */
    public function accept(Request $request, int $change): array
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;
        $change = $this->agreements->accept(AgreementChange::findOrFail($change), $request->user(), $note);

        return ['data' => self::present($change->load(['requester', 'responder']), $request->user()->id)];
    }

    /**
     * Decline a request.
     *
     * Only the side that did not ask; the agreement stays as it is.
     *
     * @return array{data: array<string, mixed>}
     */
    public function decline(Request $request, int $change): array
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;
        $change = $this->agreements->decline(AgreementChange::findOrFail($change), $request->user(), $note);

        return ['data' => self::present($change->load(['requester', 'responder']), $request->user()->id)];
    }

    /**
     * Withdraw your request.
     *
     * @return array{data: array<string, mixed>}
     */
    public function withdraw(Request $request, int $change): array
    {
        $change = $this->agreements->withdraw(AgreementChange::findOrFail($change), $request->user());

        return ['data' => self::present($change->load(['requester', 'responder']), $request->user()->id)];
    }

    /**
     * One request as JSON; `mine` tells the viewer whether they asked (and can withdraw) or answer it.
     *
     * @return array<string, mixed>
     */
    public static function present(AgreementChange $change, ?int $viewerId = null): array
    {
        return [
            'id' => $change->id,
            'projectId' => $change->project_id,
            'type' => $change->type,
            'amount' => $change->amount !== null ? (float) $change->amount : null,
            'deliveryDays' => $change->delivery_days,
            'reason' => $change->reason,
            'status' => $change->status,
            'requester' => ['id' => $change->requester->id, 'fullName' => $change->requester->full_name],
            'responder' => $change->responder ? ['id' => $change->responder->id, 'fullName' => $change->responder->full_name] : null,
            'responseNote' => $change->response_note,
            'mine' => $viewerId !== null && $change->requested_by === $viewerId,
            'respondedAt' => $change->responded_at?->toIso8601String(),
            'createdAt' => $change->created_at?->toIso8601String(),
        ];
    }
}
