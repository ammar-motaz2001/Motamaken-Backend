<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AgreementChange;
use App\Models\Escrow;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Services\Payments\EscrowService;
use Illuminate\Support\Facades\DB;

/**
 * Changing or cancelling an accepted offer by agreement. One side asks (new price and/or delivery time, or cancel),
 * the other accepts or declines; the requester can withdraw while it is pending. Only one request is open at a time.
 *
 * Money rules: the price can only change while nothing is in escrow (an unfunded payment plan is removed so the client
 * splits the new price); cancelling refunds whatever is still held in escrow to the client and closes the project.
 */
class AgreementService
{
    public function __construct(private readonly EscrowService $escrows) {}

    /**
     * Open a change or cancel request on a project in progress.
     *
     * @param  array{type: string, amount?: ?string, deliveryDays?: ?int, reason: string}  $data
     */
    public function request(Project $project, User $user, array $data): AgreementChange
    {
        $proposal = $this->acceptedProposal($project);
        $this->assertParty($project, $proposal, $user);

        if (AgreementChange::where('project_id', $project->id)->where('status', AgreementChange::PENDING)->exists()) {
            throw new ApiException(__('agreements.already_pending'), 'agreement_pending');
        }

        $escrow = Escrow::with('milestones')->where('project_id', $project->id)->first();
        if ($escrow?->milestones->contains('status', Milestone::DISPUTED)) {
            throw new ApiException(__('agreements.dispute_open'), 'dispute_open');
        }

        if ($data['type'] === AgreementChange::CHANGE) {
            $amount = isset($data['amount']) ? (string) $data['amount'] : null;
            $days = $data['deliveryDays'] ?? null;
            $priceChanges = $amount !== null && self::cents($amount) !== self::cents($proposal->amount);
            if (! $priceChanges && ($days === null || $days === $proposal->delivery_days)) {
                throw new ApiException(__('agreements.nothing_changes'), 'nothing_changes');
            }
            if ($priceChanges && $this->hasMoneyIn($escrow)) {
                throw new ApiException(__('agreements.price_locked'), 'price_locked');
            }
        }

        $change = AgreementChange::create([
            'project_id' => $project->id, 'proposal_id' => $proposal->id, 'requested_by' => $user->id, 'type' => $data['type'],
            'amount' => $data['type'] === AgreementChange::CHANGE ? ($data['amount'] ?? null) : null,
            'delivery_days' => $data['type'] === AgreementChange::CHANGE ? ($data['deliveryDays'] ?? null) : null,
            'reason' => $data['reason'],
        ]);

        $this->otherSide($project, $proposal, $user)?->notify(new ActivityNotification("project.{$data['type']}_requested", $user, $this->labels($project)));

        return $change;
    }

    /**
     * The other side accepts: a change updates the offer, a cancel refunds the escrow and closes the project.
     */
    public function accept(AgreementChange $change, User $user, ?string $note = null): AgreementChange
    {
        return DB::transaction(function () use ($change, $user, $note) {
            $change = AgreementChange::whereKey($change->id)->lockForUpdate()->firstOrFail();
            $project = Project::whereKey($change->project_id)->lockForUpdate()->firstOrFail();
            $proposal = $this->acceptedProposal($project);
            $this->assertResponder($change, $project, $proposal, $user);

            $escrow = Escrow::with(['milestones', 'client', 'project', 'payees.user'])->where('project_id', $project->id)->first();

            if ($change->type === AgreementChange::CHANGE) {
                $priceChanges = $change->amount !== null && self::cents($change->amount) !== self::cents($proposal->amount);
                if ($priceChanges && $this->hasMoneyIn($escrow)) {
                    throw new ApiException(__('agreements.price_locked'), 'price_locked');
                }
                $proposal->update(array_filter(['amount' => $change->amount, 'delivery_days' => $change->delivery_days], fn ($v) => $v !== null));
                // A payment plan for the old price is taken back; the client splits the new one.
                if ($priceChanges && $escrow) {
                    $this->escrows->cancel($escrow);
                }
            } else {
                $this->cancelAgreement($project, $escrow, $user);
            }

            $change->update(['status' => AgreementChange::ACCEPTED, 'responded_by' => $user->id, 'response_note' => $note, 'responded_at' => now()]);
            $change->requester->notify(new ActivityNotification("project.{$change->type}_accepted", $user, $this->labels($project)));

            return $change;
        });
    }

    /**
     * The other side declines; nothing changes.
     */
    public function decline(AgreementChange $change, User $user, ?string $note = null): AgreementChange
    {
        $project = $change->project;
        $this->assertResponder($change, $project, $this->acceptedProposal($project), $user);

        $change->update(['status' => AgreementChange::DECLINED, 'responded_by' => $user->id, 'response_note' => $note, 'responded_at' => now()]);
        $change->requester->notify(new ActivityNotification("project.{$change->type}_declined", $user, $this->labels($project)));

        return $change;
    }

    /**
     * The requester takes their pending request back.
     */
    public function withdraw(AgreementChange $change, User $user): AgreementChange
    {
        if ($change->requested_by !== $user->id) {
            abort(403);
        }
        $this->assertPending($change);
        $change->update(['status' => AgreementChange::WITHDRAWN]);

        return $change;
    }

    /**
     * Whether the user is the client or the hired freelancer (team lead) of the project.
     */
    public function isParty(Project $project, User $user): bool
    {
        $proposal = $project->accepted_proposal_id ? Proposal::find($project->accepted_proposal_id) : null;

        return $proposal && in_array($user->id, [$project->user_id, $proposal->user_id], true);
    }

    /**
     * Refund what is still held in escrow to the client, drop unfunded milestones and close the project.
     */
    private function cancelAgreement(Project $project, ?Escrow $escrow, User $by): void
    {
        if ($escrow && $escrow->status !== Escrow::CANCELLED) {
            $escrow->milestones()->where('status', Milestone::PENDING)->delete();
            foreach ($escrow->milestones()->whereIn('status', [Milestone::FUNDED, Milestone::SUBMITTED])->lockForUpdate()->get() as $milestone) {
                $this->escrows->settle($milestone, $escrow, $milestone->amount_cents, $by, 'escrow_refund');
            }
            $escrow->update(['status' => Escrow::CANCELLED]);
        }

        // Work already paid for counts as a completed (partial) job; otherwise the project is simply closed.
        $released = $escrow ? $escrow->load('milestones')->releasedCents() : 0;
        $project->update(['status' => $released > 0 ? Project::STATUS_COMPLETED : Project::STATUS_CLOSED]);
    }

    /**
     * Whether any money sits in or went through the escrow (so the agreed price is fixed).
     */
    private function hasMoneyIn(?Escrow $escrow): bool
    {
        return (bool) $escrow?->milestones->contains(fn (Milestone $m) => $m->status !== Milestone::PENDING);
    }

    /**
     * The accepted offer of a project in progress, or a 422 when there is no agreement to change.
     */
    private function acceptedProposal(Project $project): Proposal
    {
        if ($project->status !== Project::STATUS_IN_PROGRESS || ! $project->accepted_proposal_id) {
            throw new ApiException(__('agreements.not_in_progress'), 'not_in_progress');
        }

        return Proposal::findOrFail($project->accepted_proposal_id);
    }

    /**
     * 403 unless the user is the client or the hired freelancer.
     */
    private function assertParty(Project $project, Proposal $proposal, User $user): void
    {
        if (! in_array($user->id, [$project->user_id, $proposal->user_id], true)) {
            abort(403);
        }
    }

    /**
     * Only the side that did not ask can answer, and only while the request is pending.
     */
    private function assertResponder(AgreementChange $change, Project $project, Proposal $proposal, User $user): void
    {
        $this->assertParty($project, $proposal, $user);
        if ($change->requested_by === $user->id) {
            throw new ApiException(__('agreements.own_request'), 'own_request');
        }
        $this->assertPending($change);
    }

    /**
     * 422 unless the request is still pending.
     */
    private function assertPending(AgreementChange $change): void
    {
        if ($change->status !== AgreementChange::PENDING) {
            throw new ApiException(__('agreements.not_pending'), 'not_pending');
        }
    }

    /**
     * The side that did not make the request.
     */
    private function otherSide(Project $project, Proposal $proposal, User $user): ?User
    {
        return User::find($user->id === $project->user_id ? $proposal->user_id : $project->user_id);
    }

    /**
     * A money amount in cents, for exact comparison.
     */
    private static function cents(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    /**
     * Notification text parameters.
     *
     * @return array<string, mixed>
     */
    private function labels(Project $project): array
    {
        return ['projectId' => $project->id, 'project' => $project->title];
    }
}
