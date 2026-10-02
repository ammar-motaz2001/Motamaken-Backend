<?php

namespace App\Http\Controllers;

use App\Http\Requests\MilestoneNoteRequest;
use App\Http\Resources\MilestoneResource;
use App\Models\Milestone;
use App\Services\Payments\EscrowService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

#[Group('Escrow & milestones', 'Payment protection: the client pays milestone by milestone, the freelancer delivers, the client approves.', weight: 7)]
class MilestoneController extends Controller
{
    public function __construct(private readonly EscrowService $escrows) {}

    /**
     * Fund a milestone.
     *
     * Client only. Moves the amount from the wallet into escrow (`insufficient_funds` if the wallet is short) and tells the
     * freelancer to start.
     */
    public function fund(Request $request, int $milestone): MilestoneResource
    {
        $milestone = $this->find($milestone, 'client');

        return new MilestoneResource($this->escrows->fund($milestone, $request->user()));
    }

    /**
     * Deliver the work.
     *
     * Freelancer (or team lead) only, once the milestone is funded. The client is asked to review.
     */
    public function submit(MilestoneNoteRequest $request, int $milestone): MilestoneResource
    {
        $milestone = $this->find($milestone, 'deliver');

        return new MilestoneResource($this->escrows->submit($milestone, $request->user(), $request->input('note')));
    }

    /**
     * Approve and pay.
     *
     * Client only. Releases the milestone: the freelancer(s) receive the amount minus the platform fee. When the last
     * milestone is released the project is completed.
     */
    public function approve(Request $request, int $milestone): MilestoneResource
    {
        $milestone = $this->find($milestone, 'client');

        return new MilestoneResource($this->escrows->approve($milestone, $request->user()));
    }

    /**
     * Ask for changes.
     *
     * Client only, on delivered work. The milestone goes back to `funded` with your note.
     */
    public function requestChanges(MilestoneNoteRequest $request, int $milestone): MilestoneResource
    {
        $milestone = $this->find($milestone, 'client');

        return new MilestoneResource($this->escrows->requestChanges($milestone, $request->user(), $request->input('note')));
    }

    private function find(int $id, string $ability): Milestone
    {
        $milestone = Milestone::with('escrow.payees')->findOrFail($id);
        Gate::authorize($ability, $milestone->escrow);

        return $milestone;
    }
}
