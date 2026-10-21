<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Dispute;
use App\Models\DisputeMessage;
use App\Models\Escrow;
use App\Models\Milestone;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Services\Payments\EscrowService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * A complaint about one milestone. While it is open the milestone is frozen and the money stays in escrow; the
 * two sides explain themselves on the case timeline and an admin decides how the money is split.
 */
class DisputeService
{
    public function __construct(private readonly EscrowService $escrows) {}

    /**
     * Open a case on a funded or submitted milestone. The milestone is frozen as disputed (its previous status is kept for a
     * withdrawal), the other side and the admins are told.
     */
    public function open(Milestone $milestone, User $opener, string $reason, string $description): Dispute
    {
        $dispute = DB::transaction(function () use ($milestone, $opener, $reason, $description) {
            $locked = Milestone::whereKey($milestone->id)->lockForUpdate()->firstOrFail();
            $escrow = Escrow::with(['payees.user', 'client', 'project'])->findOrFail($locked->escrow_id);

            if (! in_array($locked->status, [Milestone::FUNDED, Milestone::SUBMITTED], true)) {
                throw new ApiException(__('disputes.not_disputable'), 'milestone_not_disputable');
            }

            $isClient = $opener->id === $escrow->client_id;
            $against = $isClient ? $escrow->payees->firstWhere('is_lead', true)->user : $escrow->client;

            $dispute = Dispute::create([
                'escrow_id' => $escrow->id, 'milestone_id' => $locked->id, 'opened_by' => $opener->id,
                'against_id' => $against->id, 'reason' => $reason, 'description' => $description,
                'milestone_status_before' => $locked->status,
            ]);
            $locked->update(['status' => Milestone::DISPUTED]);

            $this->record($dispute, 'dispute.opened', $opener);

            return $dispute->setRelation('escrow', $escrow)->setRelation('milestone', $locked);
        });

        $params = $this->params($dispute);
        $dispute->respondent->notify(new ActivityNotification('dispute.opened', $opener, $params));
        $this->notifyAdmins('admin.dispute_opened', $opener, $params);

        return $dispute;
    }

    /**
     * Add to the case timeline. Admins may also leave notes the two sides cannot see.
     */
    public function post(Dispute $dispute, User $author, ?string $body, ?UploadedFile $file, bool $internal = false): DisputeMessage
    {
        if (! $dispute->isActive()) {
            throw new ApiException(__('disputes.closed'), 'dispute_closed');
        }

        $message = $dispute->messages()->create([
            'user_id' => $author->id,
            'kind' => $internal ? DisputeMessage::ADMIN_NOTE : DisputeMessage::MESSAGE,
            'body' => $body,
            'attachment_path' => $file?->store("disputes/{$dispute->id}", 'local'),
            'attachment_name' => $file?->getClientOriginalName(),
            'attachment_mime' => $file?->getMimeType(),
            'attachment_size' => $file?->getSize(),
        ]);

        if (! $internal) {
            $dispute->loadMissing('escrow.payees.user');

            foreach ($this->partiesOf($dispute) as $party) {
                if ($party->id !== $author->id) {
                    $party->notify(new ActivityNotification('dispute.message', $author, $this->params($dispute)));
                }
            }
        }

        return $message;
    }

    /**
     * An admin takes the case.
     */
    public function review(Dispute $dispute, User $admin): Dispute
    {
        if ($dispute->status !== Dispute::OPEN) {
            throw new ApiException(__('disputes.closed'), 'dispute_closed');
        }

        $dispute->update(['status' => Dispute::UNDER_REVIEW, 'assigned_to' => $admin->id]);
        $this->record($dispute, 'dispute.under_review', $admin);

        foreach ($this->partiesOf($dispute->loadMissing('escrow.payees.user')) as $party) {
            $party->notify(new ActivityNotification('dispute.under_review', null, $this->params($dispute)));
        }

        return $dispute;
    }

    /**
     * The person who opened the case takes it back: the milestone goes back to where it was.
     */
    public function withdraw(Dispute $dispute, User $user): Dispute
    {
        DB::transaction(function () use ($dispute, $user) {
            $locked = $this->lock($dispute);

            $locked->milestone->update(['status' => $locked->milestone_status_before]);
            $locked->update(['status' => Dispute::WITHDRAWN, 'resolved_at' => now()]);
            $this->record($locked, 'dispute.withdrawn', $user);
        });

        $dispute->respondent->notify(new ActivityNotification('dispute.withdrawn', $user, $this->params($dispute)));

        return $dispute->refresh();
    }

    /**
     * The other side agrees: no need to wait for an admin. A freelancer concedes by refunding the client; a client
     * concedes by letting the freelancer be paid in full.
     */
    public function concede(Dispute $dispute, User $user): Dispute
    {
        $dispute->loadMissing('escrow.payees');
        $refund = $dispute->escrow->payees->contains('user_id', $user->id);

        return $this->settle($dispute, $user, $refund ? Dispute::REFUND_CLIENT : Dispute::PAY_FREELANCER, null, null, 'dispute.conceded');
    }

    /**
     * An admin decides.
     */
    public function resolve(Dispute $dispute, User $admin, string $resolution, ?int $clientPercent, ?string $note): Dispute
    {
        return $this->settle($dispute, $admin, $resolution, $clientPercent, $note, 'dispute.resolved');
    }

    /**
     * Split the disputed milestone between the client and the freelancer: the client's part is refunded from escrow and
     * the rest released, then the case is closed as resolved and both sides are told.
     */
    private function settle(Dispute $dispute, User $by, string $resolution, ?int $clientPercent, ?string $note, string $event): Dispute
    {
        DB::transaction(function () use ($dispute, $by, $resolution, $clientPercent, $note, $event) {
            $locked = $this->lock($dispute);
            $milestone = $locked->milestone;
            $escrow = $locked->escrow;

            $toClient = match ($resolution) {
                Dispute::REFUND_CLIENT => $milestone->amount_cents,
                Dispute::PAY_FREELANCER => 0,
                Dispute::SPLIT => intdiv($milestone->amount_cents * (int) $clientPercent, 100),
            };

            $this->escrows->settle($milestone, $escrow, $toClient, $by, 'dispute_settlement');

            $locked->update([
                'status' => Dispute::RESOLVED, 'resolution' => $resolution,
                'client_percent' => $resolution === Dispute::SPLIT ? $clientPercent : null,
                'resolution_note' => $note, 'resolved_at' => now(),
            ]);
            $this->record($locked, $event, $by);
        });

        foreach ($this->partiesOf($dispute->refresh()->loadMissing('escrow.payees.user')) as $party) {
            $party->notify(new ActivityNotification('dispute.resolved', null, $this->params($dispute)));
        }

        return $dispute;
    }

    /**
     * The case, locked and still active, with everything the settlement needs.
     */
    private function lock(Dispute $dispute): Dispute
    {
        $locked = Dispute::whereKey($dispute->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isActive()) {
            throw new ApiException(__('disputes.closed'), 'dispute_closed');
        }

        $locked->setRelation('milestone', Milestone::whereKey($locked->milestone_id)->lockForUpdate()->firstOrFail());
        $locked->setRelation('escrow', Escrow::with(['client', 'project', 'payees.user'])->findOrFail($locked->escrow_id));

        return $locked;
    }

    /**
     * Write a system event (opened, resolved, ...) on the case timeline.
     */
    private function record(Dispute $dispute, string $event, User $by): void
    {
        $dispute->messages()->create(['user_id' => $by->id, 'kind' => DisputeMessage::SYSTEM, 'body' => $event]);
    }

    /**
     * The client and everyone being paid.
     *
     * @return list<User>
     */
    private function partiesOf(Dispute $dispute): array
    {
        $dispute->loadMissing(['escrow.client', 'escrow.payees.user']);

        return [$dispute->escrow->client, ...$dispute->escrow->payees->pluck('user')->all()];
    }

    /**
     * The case, project and milestone labels every dispute notification carries.
     *
     * @return array<string, scalar|null>
     */
    private function params(Dispute $dispute): array
    {
        $dispute->loadMissing(['escrow.project', 'milestone']);

        return [
            'disputeId' => $dispute->id, 'case' => $dispute->caseNumber(),
            'projectId' => $dispute->escrow->project_id, 'project' => $dispute->escrow->project->title,
            'milestoneId' => $dispute->milestone_id, 'milestone' => $dispute->milestone->title,
        ];
    }

    /**
     * Send the same notification to every admin.
     *
     * @param  array<string, scalar|null>  $params
     */
    private function notifyAdmins(string $type, User $actor, array $params): void
    {
        foreach (User::where('is_admin', true)->get() as $admin) {
            $admin->notify(new ActivityNotification($type, $actor, $params));
        }
    }
}
