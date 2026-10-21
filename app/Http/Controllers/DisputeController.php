<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Requests\DisputeMessageRequest;
use App\Http\Requests\OpenDisputeRequest;
use App\Http\Requests\PaginatedRequest;
use App\Http\Resources\DisputeMessageResource;
use App\Http\Resources\DisputeResource;
use App\Models\Dispute;
use App\Models\DisputeMessage;
use App\Models\Milestone;
use App\Services\AppealService;
use App\Services\DisputeService;
use App\Support\RuntimeConfig;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Group('Disputes', 'Open a case on a milestone, explain and upload evidence; an admin settles how the escrowed money is split.', weight: 9)]
class DisputeController extends Controller
{
    private const WITH = ['opener', 'respondent', 'milestone', 'escrow.project'];

    public function __construct(private readonly DisputeService $disputes) {}

    /**
     * Open a dispute.
     *
     * The client, or the freelancer (for a team: its lead), on a milestone that is funded or delivered. The milestone is frozen (nobody can pay it, deliver
     * it or change it) and the money stays in escrow until the case is settled. The other side and the admins are told.
     */
    public function store(OpenDisputeRequest $request, int $milestone): JsonResponse
    {
        $milestone = Milestone::with('escrow.payees')->findOrFail($milestone);
        Gate::authorize('dispute', $milestone->escrow);

        $dispute = $this->disputes->open($milestone, $request->user(), $request->input('reason'), $request->input('description'));

        return (new DisputeResource($dispute->load([...self::WITH, 'messages.user'])))->response()->setStatusCode(201);
    }

    /**
     * My disputes.
     *
     * Cases the user opened or is on a side of, newest first.
     */
    public function index(PaginatedRequest $request): AnonymousResourceCollection
    {
        $me = $request->user()->id;

        return DisputeResource::collection(
            Dispute::with(self::WITH)
                ->where(fn ($query) => $query->where('opened_by', $me)->orWhere('against_id', $me)
                    ->orWhereHas('escrow', fn ($q) => $q->where('client_id', $me)->orWhereHas('payees', fn ($p) => $p->where('user_id', $me))))
                ->latest()->latest('id')
                ->paginate($request->integer('perPage', 15)),
        );
    }

    /**
     * Case page.
     *
     * Summary, the decision once there is one, and the whole timeline. `permissions` says which buttons to show.
     */
    public function show(Request $request, int $dispute): DisputeResource
    {
        return new DisputeResource($this->find($dispute, $request)->load([...self::WITH, 'messages.user', 'assignee']));
    }

    /**
     * Add to the case.
     *
     * Write a message and/or upload evidence (`multipart/form-data`). The other side is notified.
     */
    public function message(DisputeMessageRequest $request, int $dispute): JsonResponse
    {
        $dispute = $this->find($dispute, $request);
        $message = $this->disputes->post($dispute, $request->user(), $request->input('body'), $request->file('file'));

        return (new DisputeMessageResource($message->load('user')))->response()->setStatusCode(201);
    }

    /**
     * Withdraw the dispute.
     *
     * Only the person who opened it. The milestone goes back to where it was.
     */
    public function withdraw(Request $request, int $dispute): DisputeResource
    {
        $dispute = $this->find($dispute, $request);
        abort_unless($dispute->opened_by === $request->user()->id, 403);

        return new DisputeResource($this->disputes->withdraw($dispute, $request->user())->load(self::WITH));
    }

    /**
     * Agree with the other side.
     *
     * The side that did not open the case gives in and it is settled at once, without an admin (for a team, its lead decides): a freelancer concedes by
     * refunding the client in full, a client by paying the freelancer in full.
     */
    public function concede(Request $request, int $dispute): DisputeResource
    {
        $dispute = $this->find($dispute, $request);
        $mySide = $dispute->sideOf($request->user());
        abort_if($mySide === null || $mySide === $dispute->sideOf($dispute->opener), 403);
        // A team speaks with one voice: only its lead can give in for it.
        Gate::authorize('dispute', $dispute->escrow);

        return new DisputeResource($this->disputes->concede($dispute, $request->user())->load(self::WITH));
    }

    /**
     * Appeal the decision.
     *
     * Either side can ask once for a second look within a few days of the settlement, with the reason and optional new
     * evidence (`multipart/form-data`). The staff uphold the decision or change it with a money correction.
     */
    public function appeal(Request $request, int $dispute): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:20', 'max:3000'],
            'file' => ['nullable', 'file', 'mimes:'.RuntimeConfig::attachmentTypes(), 'max:'.RuntimeConfig::uploadKb('document')],
        ]);

        $dispute = $this->find($dispute, $request);
        $appeal = app(AppealService::class)->file($dispute, $request->user(), $data['reason'], $request->file('file'));

        return response()->json(['data' => ['id' => $appeal->id, 'status' => $appeal->status]], 201);
    }

    /**
     * Download evidence.
     *
     * Reached through the signed `attachment.url` of a timeline entry (works in `<img>` tags; expires after an hour).
     *
     * @unauthenticated
     */
    public function attachment(int $message): StreamedResponse
    {
        $message = DisputeMessage::whereNotNull('attachment_path')->where('kind', '!=', DisputeMessage::ADMIN_NOTE)->findOrFail($message);
        $inline = in_array($message->attachment_mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);

        return Storage::disk('local')->response(
            $message->attachment_path, $message->attachment_name,
            ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=600'],
            $inline ? 'inline' : 'attachment',
        );
    }

    /**
     * Loads a dispute that the user is a party to; admins can open any.
     */
    private function find(int $id, Request $request): Dispute
    {
        $dispute = Dispute::with(['escrow.payees', 'opener'])->findOrFail($id);

        if (! $request->user()->is_admin && ! $dispute->involves($request->user())) {
            throw new ApiException(__('disputes.not_yours'), 'forbidden', 403);
        }

        return $dispute;
    }
}
