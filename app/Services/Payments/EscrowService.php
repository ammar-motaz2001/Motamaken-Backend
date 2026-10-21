<?php

namespace App\Services\Payments;

use App\Exceptions\ApiException;
use App\Models\Account;
use App\Models\Escrow;
use App\Models\EscrowPayee;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\Tip;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Services\AffiliateService;
use App\Services\SubscriptionService;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Payment protection: the client pays each milestone into escrow, the freelancer delivers, the client approves and the
 * money goes to the freelancer minus the platform fee. Every step is one ledger movement.
 */
class EscrowService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly FeeCalculator $fees,
        private readonly SubscriptionService $subscriptions,
        private readonly AffiliateService $affiliates,
    ) {}

    /**
     * Set up the milestones for a hired project. Their amounts must add up to the accepted offer.
     *
     * @param  list<array{title: string, amount: int|float|string, dueDate?: ?string}>  $milestones
     */
    public function create(Project $project, User $client, array $milestones): Escrow
    {
        if ($project->status !== Project::STATUS_IN_PROGRESS || ! $project->accepted_proposal_id) {
            throw new ApiException(__('payments.escrow_needs_hired'), 'escrow_needs_hired');
        }

        if (Escrow::where('project_id', $project->id)->exists()) {
            throw new ApiException(__('payments.escrow_exists'), 'escrow_exists');
        }

        $proposal = Proposal::findOrFail($project->accepted_proposal_id);
        $total = Money::toCents($proposal->amount);
        $cents = array_map(fn (array $m) => Money::toCents($m['amount']), $milestones);

        if (array_sum($cents) !== $total) {
            throw new ApiException(
                __('payments.milestones_total', ['total' => Money::fromCents($total), 'currency' => $proposal->currency]),
                'milestones_total',
            );
        }

        $escrow = DB::transaction(function () use ($project, $client, $proposal, $total, $milestones, $cents) {
            $escrow = Escrow::create([
                'project_id' => $project->id, 'proposal_id' => $proposal->id, 'client_id' => $client->id,
                'currency' => $proposal->currency, 'total_cents' => $total,
            ]);

            $payees = $this->payeesFor($proposal);
            foreach ($payees as $payee) {
                $escrow->payees()->create([...$payee, 'split_status' => count($payees) > 1 ? EscrowPayee::SPLIT_PENDING : EscrowPayee::SPLIT_APPROVED]);
            }

            foreach ($milestones as $index => $milestone) {
                $escrow->milestones()->create([
                    'position' => $index + 1, 'title' => $milestone['title'],
                    'amount_cents' => $cents[$index], 'due_date' => $milestone['dueDate'] ?? null,
                ]);
            }

            return $escrow->load(['milestones', 'payees.user']);
        });

        $this->notifyPayees($escrow, 'escrow.created', $client, ['projectId' => $project->id, 'project' => $project->title, 'escrowId' => $escrow->id]);

        return $escrow;
    }

    /**
     * The client pays one milestone into escrow.
     */
    public function fund(Milestone $milestone, User $client): Milestone
    {
        DB::transaction(function () use ($milestone, $client) {
            [$milestone, $escrow] = $this->lockMilestone($milestone);

            if ($milestone->status !== Milestone::PENDING) {
                throw new ApiException(__('payments.milestone_not_pending'), 'milestone_not_pending');
            }

            $this->ledger->post('escrow_fund', [
                [$this->ledger->wallet($client, $escrow->currency), -$milestone->amount_cents],
                [$this->ledger->system(Account::ESCROW, $escrow->currency), $milestone->amount_cents],
            ], [
                'reference' => $milestone, 'key' => "milestone:{$milestone->id}:fund", 'by' => $client,
                'meta' => $this->labels($escrow, $milestone),
            ]);

            $milestone->update(['status' => Milestone::FUNDED, 'funded_at' => now()]);
            $escrow->update(['status' => Escrow::ACTIVE]);

            $this->notifyPayees($escrow, 'milestone.funded', $client, $this->labels($escrow, $milestone));
        });

        return $milestone->refresh();
    }

    /**
     * Pay every milestone that is still waiting, or none if the wallet cannot cover all of them.
     */
    public function fundAll(Escrow $escrow, User $client): Escrow
    {
        DB::transaction(function () use ($escrow, $client) {
            foreach ($escrow->milestones()->where('status', Milestone::PENDING)->get() as $milestone) {
                $this->fund($milestone, $client);
            }
        });

        return $escrow->refresh();
    }

    /**
     * A person being paid hands in the work for a funded milestone.
     */
    public function submit(Milestone $milestone, User $payee, ?string $note): Milestone
    {
        DB::transaction(function () use ($milestone, $payee, $note) {
            [$milestone, $escrow] = $this->lockMilestone($milestone);

            if ($milestone->status !== Milestone::FUNDED) {
                throw new ApiException(
                    __($milestone->status === Milestone::PENDING ? 'payments.milestone_not_funded' : 'payments.milestone_wrong_state'),
                    $milestone->status === Milestone::PENDING ? 'milestone_not_funded' : 'milestone_wrong_state',
                );
            }

            $milestone->update(['status' => Milestone::SUBMITTED, 'submitted_at' => now(), 'submission_note' => $note, 'changes_note' => null]);

            $escrow->client->notify(new ActivityNotification('milestone.submitted', $payee, $this->labels($escrow, $milestone)));
        });

        return $milestone->refresh();
    }

    /**
     * The client sends delivered work back with a note.
     */
    public function requestChanges(Milestone $milestone, User $client, string $note): Milestone
    {
        DB::transaction(function () use ($milestone, $client, $note) {
            [$milestone, $escrow] = $this->lockMilestone($milestone);

            if ($milestone->status !== Milestone::SUBMITTED) {
                throw new ApiException(__('payments.milestone_not_submitted'), 'milestone_not_submitted');
            }

            $milestone->update(['status' => Milestone::FUNDED, 'changes_note' => $note]);

            $this->notifyPayees($escrow, 'milestone.changes_requested', $client, $this->labels($escrow, $milestone));
        });

        return $milestone->refresh();
    }

    /**
     * The client is happy: the money goes to the freelancer(s), minus the platform fee.
     */
    public function approve(Milestone $milestone, User $client): Milestone
    {
        DB::transaction(function () use ($milestone, $client) {
            [$milestone, $escrow] = $this->lockMilestone($milestone);

            if (! in_array($milestone->status, [Milestone::FUNDED, Milestone::SUBMITTED], true)) {
                throw new ApiException(
                    __($milestone->status === Milestone::PENDING ? 'payments.milestone_not_funded' : 'payments.milestone_wrong_state'),
                    $milestone->status === Milestone::PENDING ? 'milestone_not_funded' : 'milestone_wrong_state',
                );
            }

            if (! $escrow->loadMissing('payees')->splitApproved()) {
                throw new ApiException(__('payments.split_not_approved'), 'split_not_approved');
            }

            $this->settle($milestone, $escrow, toClient: 0, by: $client);
        });

        return $milestone->refresh();
    }

    /**
     * A team member answers the payout split: approve their share, or object with a reason (which blocks releases).
     */
    public function answerSplit(Escrow $escrow, User $member, bool $approve, ?string $note): Escrow
    {
        $payee = $escrow->payees->firstWhere('user_id', $member->id) ?? throw new ApiException(__('payments.not_a_payee'), 'not_a_payee', 403);

        if ($escrow->status === Escrow::COMPLETED) {
            throw new ApiException(__('payments.split_closed'), 'split_closed');
        }

        $payee->update([
            'split_status' => $approve ? EscrowPayee::SPLIT_APPROVED : EscrowPayee::SPLIT_OBJECTED,
            'split_note' => $approve ? null : $note,
            'split_answered_at' => now(),
        ]);

        $escrow->load(['payees.user', 'project', 'client']);
        $params = ['projectId' => $escrow->project_id, 'project' => $escrow->project->title, 'escrowId' => $escrow->id];

        if (! $approve) {
            $this->notifyPayees($escrow, 'escrow.split_objected', $member, $params);
            $escrow->client->notify(new ActivityNotification('escrow.split_objected', $member, $params));
        } elseif ($escrow->splitApproved() && $escrow->payees->count() > 1) {
            $escrow->client->notify(new ActivityNotification('escrow.split_approved', null, $params));
        }

        return $escrow;
    }

    /**
     * The client thanks the freelancer(s) with a tip from the wallet, split by share. No platform fee is taken.
     */
    public function tip(Escrow $escrow, User $client, int $cents, ?string $note): Tip
    {
        return DB::transaction(function () use ($escrow, $client, $cents, $note) {
            $escrow->loadMissing(['payees.user', 'project']);

            if (! $escrow->milestones()->where('status', Milestone::RELEASED)->exists()) {
                throw new ApiException(__('payments.tip_too_early'), 'tip_too_early');
            }

            $tip = Tip::create(['escrow_id' => $escrow->id, 'client_id' => $client->id, 'amount_cents' => $cents, 'currency' => $escrow->currency, 'note' => $note]);
            $payouts = $this->distribute($cents, $escrow->payees);

            $legs = [[$this->ledger->wallet($client, $escrow->currency), -$cents]];
            foreach ($payouts as $userId => $share) {
                if ($share > 0) {
                    $legs[] = [$this->ledger->wallet($escrow->payees->firstWhere('user_id', $userId)->user, $escrow->currency), $share];
                }
            }
            $this->ledger->post('tip', $legs, ['reference' => $tip, 'by' => $client, 'meta' => ['project' => $escrow->project->title]]);

            foreach ($payouts as $userId => $share) {
                $escrow->payees->firstWhere('user_id', $userId)->user->notify(new ActivityNotification('escrow.tip', $client, [
                    'projectId' => $escrow->project_id, 'project' => $escrow->project->title, 'amount' => Money::fromCents($share), 'currency' => $escrow->currency,
                ]));
            }

            return $tip;
        });
    }

    /**
     * Take back an escrow nothing was paid into.
     */
    public function cancel(Escrow $escrow): void
    {
        if ($escrow->milestones()->where('status', '!=', Milestone::PENDING)->exists()) {
            throw new ApiException(__('payments.escrow_has_funds'), 'escrow_has_funds');
        }

        $escrow->delete();
    }

    /**
     * Pay out a milestone that sits in escrow: `$toClient` cents go back to the client and the rest to the freelancer(s),
     * minus the platform fee on their part. Used for normal approval (toClient = 0) and for settling disputes.
     */
    public function settle(Milestone $milestone, Escrow $escrow, int $toClient, ?User $by = null, string $type = 'escrow_release'): void
    {
        $escrow->loadMissing(['client', 'project', 'payees.user']);

        $toPayees = $milestone->amount_cents - $toClient;
        // The fee comes out of what the freelancer earns, at their plan's rate.
        $fee = $this->fees->forRelease($toPayees, $this->subscriptions->feePercentFor($escrow->payees->firstWhere('is_lead', true)?->user));
        $payouts = $this->distribute($toPayees - $fee, $escrow->payees);

        $legs = [[$this->ledger->system(Account::ESCROW, $escrow->currency), -$milestone->amount_cents]];

        if ($toClient > 0) {
            $legs[] = [$this->ledger->wallet($escrow->client, $escrow->currency), $toClient];
        }

        foreach ($payouts as $userId => $cents) {
            if ($cents > 0) {
                $legs[] = [$this->ledger->wallet($escrow->payees->firstWhere('user_id', $userId)->user, $escrow->currency), $cents];
            }
        }

        // Referrers of the people being paid take their share out of the platform's fee.
        $commissions = $toPayees > 0 ? $this->affiliates->commissionsFor($escrow, $this->distribute($fee, $escrow->payees)) : [];
        $commissionTotal = array_sum(array_column($commissions, 'cents'));

        foreach (collect($commissions)->where('cents', '>', 0)->groupBy(fn (array $c) => $c['referrer']->id) as $group) {
            $legs[] = [$this->ledger->wallet($group[0]['referrer'], $escrow->currency), collect($group)->sum('cents')];
        }

        if ($fee - $commissionTotal > 0) {
            $legs[] = [$this->ledger->system(Account::REVENUE, $escrow->currency), $fee - $commissionTotal];
        }

        $this->ledger->post($type, $legs, [
            'reference' => $milestone, 'key' => "milestone:{$milestone->id}:settle", 'by' => $by,
            'meta' => [...$this->labels($escrow, $milestone), 'fee_cents' => $fee, 'to_client_cents' => $toClient, 'affiliate_cents' => $commissionTotal],
        ]);

        $this->affiliates->record($milestone, $escrow->currency, $commissions);

        $milestone->update([
            'status' => $toPayees === 0 ? Milestone::REFUNDED : Milestone::RELEASED,
            'fee_cents' => $fee,
            'refunded_cents' => $toClient,
            'released_at' => now(),
        ]);

        foreach ($payouts as $userId => $cents) {
            $escrow->payees->firstWhere('user_id', $userId)->user->notify(new ActivityNotification(
                'milestone.released', $escrow->client, [...$this->labels($escrow, $milestone), 'amount' => Money::fromCents($cents)],
            ));
        }

        $this->completeIfFinished($escrow);
    }

    /**
     * Who is paid and in what share. A solo freelancer takes everything.
     *
     * @return list<array{user_id: int, share_bp: int, is_lead: bool}>
     */
    protected function payeesFor(Proposal $proposal): array
    {
        if (! $proposal->team_id) {
            return [['user_id' => $proposal->user_id, 'share_bp' => 10000, 'is_lead' => true]];
        }

        // The team as it is now: every accepted member with the share they approved. Fixed for the life of the escrow.
        $members = TeamMember::where('team_id', $proposal->team_id)->where('status', TeamMember::ACCEPTED)->get();

        if ($members->sum('share_bp') !== Team::FULL_SHARE_BP) {
            throw new ApiException(__('teams.not_ready'), 'team_not_ready');
        }

        return $members->map(fn (TeamMember $m) => ['user_id' => $m->user_id, 'share_bp' => $m->share_bp, 'is_lead' => $m->is_lead])->all();
    }

    /**
     * Split cents by share. Rounding leftovers go to the lead so not a cent is lost.
     *
     * @param  Collection<int, EscrowPayee>  $payees
     * @return array<int, int> user id => cents
     */
    public function distribute(int $cents, Collection $payees): array
    {
        $payouts = $payees->mapWithKeys(fn (EscrowPayee $payee) => [$payee->user_id => intdiv($cents * $payee->share_bp, 10000)])->all();
        $lead = $payees->firstWhere('is_lead', true) ?? $payees->first();
        $payouts[$lead->user_id] += $cents - array_sum($payouts);

        return $payouts;
    }

    /**
     * Once every milestone is released or refunded, complete the escrow and tell everyone. The project is completed when
     * anything was paid out, otherwise closed.
     */
    private function completeIfFinished(Escrow $escrow): void
    {
        $escrow->load('milestones');

        if ($escrow->milestones->contains(fn (Milestone $m) => ! in_array($m->status, [Milestone::RELEASED, Milestone::REFUNDED], true))) {
            return;
        }

        $escrow->update(['status' => Escrow::COMPLETED]);
        // Nothing was paid out (everything refunded after a dispute): the project was cancelled, not completed.
        $escrow->project->update(['status' => $escrow->releasedCents() > 0 ? Project::STATUS_COMPLETED : Project::STATUS_CLOSED]);

        $labels = ['projectId' => $escrow->project_id, 'project' => $escrow->project->title, 'escrowId' => $escrow->id];
        $escrow->client->notify(new ActivityNotification('escrow.completed', null, $labels));
        $this->notifyPayees($escrow, 'escrow.completed', null, $labels);
    }

    /**
     * Re-read the milestone and its escrow under a lock, so two clicks cannot pay or release it twice.
     *
     * @return array{0: Milestone, 1: Escrow}
     */
    private function lockMilestone(Milestone $milestone): array
    {
        $locked = Milestone::whereKey($milestone->id)->lockForUpdate()->firstOrFail();
        $escrow = Escrow::with(['client', 'project', 'payees.user'])->findOrFail($locked->escrow_id);

        return [$locked, $escrow];
    }

    /**
     * The project, milestone and amount labels escrow notifications carry.
     *
     * @return array<string, scalar|null>
     */
    private function labels(Escrow $escrow, Milestone $milestone): array
    {
        return [
            'projectId' => $escrow->project_id, 'project' => $escrow->project->title, 'escrowId' => $escrow->id,
            'milestoneId' => $milestone->id, 'milestone' => $milestone->title,
            'amount' => Money::fromCents($milestone->amount_cents), 'currency' => $escrow->currency,
        ];
    }

    /**
     * Send the same notification to everyone being paid on the escrow.
     *
     * @param  array<string, scalar|null>  $params
     */
    private function notifyPayees(Escrow $escrow, string $type, ?User $actor, array $params): void
    {
        $escrow->loadMissing('payees.user');

        foreach ($escrow->payees as $payee) {
            $payee->user->notify(new ActivityNotification($type, $actor, $params));
        }
    }
}
