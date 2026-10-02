<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\DisputeMessageRequest;
use App\Http\Requests\ResolveDisputeRequest;
use App\Http\Resources\DisputeMessageResource;
use App\Http\Resources\DisputeResource;
use App\Models\Dispute;
use App\Services\DisputeService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminDisputeController extends Controller
{
    private const WITH = ['opener', 'respondent', 'milestone', 'escrow.project', 'assignee'];

    public function __construct(private readonly DisputeService $disputes) {}

    /**
     * List disputes.
     *
     * Oldest first for active cases (the queue), newest first otherwise. Open the case itself with `GET /disputes/{id}`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::in([Dispute::OPEN, Dispute::UNDER_REVIEW, Dispute::RESOLVED, Dispute::WITHDRAWN])],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $status = $request->input('status');

        return DisputeResource::collection(
            Dispute::with(self::WITH)
                ->when($status, fn ($query) => $query->where('status', $status), fn ($query) => $query->whereIn('status', Dispute::ACTIVE))
                ->orderBy('id', in_array($status, [null, Dispute::OPEN, Dispute::UNDER_REVIEW], true) ? 'asc' : 'desc')
                ->paginate($request->integer('perPage', 20)),
        );
    }

    /**
     * Take the case.
     *
     * Marks it `under_review`, assigns it to you and tells both sides.
     */
    public function review(Request $request, int $dispute): DisputeResource
    {
        $dispute = Dispute::with(['escrow.payees.user'])->findOrFail($dispute);

        return new DisputeResource($this->disputes->review($dispute, $request->user())->load(self::WITH));
    }

    /**
     * Settle the case.
     *
     * Decide where the frozen milestone's money goes: all back to the client, all to the freelancer(s) (minus the platform
     * fee), or a percentage split. The decision and your note are shown to both sides.
     */
    public function resolve(ResolveDisputeRequest $request, int $dispute): DisputeResource
    {
        $dispute = Dispute::findOrFail($dispute);

        return new DisputeResource($this->disputes->resolve(
            $dispute, $request->user(), $request->input('resolution'), $request->input('clientPercent'), $request->input('note'),
        )->load([...self::WITH, 'messages.user']));
    }

    /**
     * Write to the case.
     *
     * A message both sides see (`internal` false, the default), or a private note for admins (`internal` true).
     */
    public function message(DisputeMessageRequest $request, int $dispute): JsonResponse
    {
        $dispute = Dispute::findOrFail($dispute);
        $message = $this->disputes->post(
            $dispute, $request->user(), $request->input('body'), $request->file('file'), $request->boolean('internal'),
        );

        return (new DisputeMessageResource($message->load('user')))->response()->setStatusCode(201);
    }
}
