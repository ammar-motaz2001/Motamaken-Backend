<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Escrow;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Notifications\ProposalActivity;
use App\Services\Chat\ConversationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ProposalService
{
    public function __construct(
        private readonly ConversationService $chat,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * "Add Your Offer".
     *
     * @param  array{amount: int|float, deliveryDays: int, message: string, teamId?: ?int}  $data
     */
    public function submit(Project $project, User $offerer, array $data): Proposal
    {
        $this->assertCanOffer($project, $offerer);
        $this->subscriptions->assertCanSendOffer($offerer);
        $team = isset($data['teamId']) ? $this->teamFor($project, $offerer, (int) $data['teamId']) : null;

        try {
            $proposal = DB::transaction(function () use ($project, $offerer, $data, $team) {
                $proposal = $project->proposals()->create([
                    'user_id' => $offerer->id,
                    'team_id' => $team?->id,
                    'amount' => $data['amount'],
                    'currency' => $project->currency,
                    'delivery_days' => $data['deliveryDays'],
                    'message' => $data['message'],
                ]);

                $project->user->notify(new ProposalActivity(ProposalActivity::RECEIVED, $offerer, $project, $proposal));
                // The sender's own confirmation (bell and email).
                $offerer->notify(new ActivityNotification('proposal.sent', null, ['projectId' => $project->id, 'project' => $project->title]));

                return $proposal;
            });
        } catch (UniqueConstraintViolationException) {
            // A double click sent two offers at once; the first one won.
            throw new ApiException(__('proposals.already_offered'), 'already_offered');
        }

        return $proposal;
    }

    /**
     * Change the amount, delivery time or message of an offer nobody has answered yet.
     *
     * @param  array{amount?: int|float, deliveryDays?: int, message?: string}  $data
     */
    public function update(Proposal $proposal, array $data): Proposal
    {
        $this->assertPending($proposal);
        $this->assertProjectOpen($proposal->project);

        $proposal->update(array_filter([
            'amount' => $data['amount'] ?? null,
            'delivery_days' => $data['deliveryDays'] ?? null,
            'message' => $data['message'] ?? null,
        ], fn ($value) => $value !== null));

        return $proposal;
    }

    /**
     * The offerer takes back an offer nobody has answered yet.
     */
    public function withdraw(Proposal $proposal): Proposal
    {
        $this->assertPending($proposal);
        $proposal->update(['status' => Proposal::STATUS_WITHDRAWN]);

        return $proposal;
    }

    /**
     * The owner picks this offer: the project goes in progress and every other pending offer is declined.
     */
    public function accept(Proposal $proposal): Proposal
    {
        DB::transaction(function () use ($proposal) {
            // Two owners' clicks (or two tabs) cannot both win: the project row is locked until we finish.
            $project = Project::whereKey($proposal->project_id)->lockForUpdate()->firstOrFail();
            $proposal->refresh();

            $this->assertPending($proposal);
            $this->assertProjectOpen($project);

            if ($proposal->team_id && ! $proposal->team()->where('status', Team::ACTIVE)->exists()) {
                throw new ApiException(__('teams.not_active'), 'team_not_active');
            }

            $proposal->update(['status' => Proposal::STATUS_ACCEPTED]);
            $project->update(['status' => Project::STATUS_IN_PROGRESS, 'accepted_proposal_id' => $proposal->id]);

            $proposal->user->notify(new ProposalActivity(ProposalActivity::ACCEPTED, $project->user, $project, $proposal));
            $this->declinePending($project, $project->user);

            $proposal->setRelation('project', $project);
        });

        // A team project gets a group chat for the client and the whole team.
        if ($proposal->team_id) {
            $team = $proposal->team()->first();
            $this->chat->ensureProjectGroup(
                $proposal->project_id, $proposal->project->user, __('teams.group_title', ['name' => $team->name]),
                $team->members()->where('status', TeamMember::ACCEPTED)->pluck('user_id')->all(),
            );
        }

        return $proposal;
    }

    /**
     * The project owner turns down a pending offer; the offerer is told.
     */
    public function decline(Proposal $proposal): Proposal
    {
        $this->assertPending($proposal);
        $proposal->update(['status' => Proposal::STATUS_DECLINED]);
        $proposal->user->notify(new ProposalActivity(ProposalActivity::DECLINED, $proposal->project->user, $proposal->project, $proposal));

        return $proposal;
    }

    /**
     * Cancel a project nobody was hired for. Pending offers are declined and their owners told.
     */
    public function close(Project $project): Project
    {
        $this->assertProjectOpen($project);

        DB::transaction(function () use ($project) {
            $project->update(['status' => Project::STATUS_CLOSED]);
            $this->declinePending($project, $project->user);
        });

        return $project;
    }

    /**
     * The owner marks a project in progress as done. Refused while any of its escrows is not completed, since those
     * complete the project themselves.
     */
    public function complete(Project $project): Project
    {
        if ($project->status !== Project::STATUS_IN_PROGRESS) {
            throw new ApiException(__('proposals.project_not_in_progress'), 'project_not_in_progress');
        }

        // With payment protection the project completes by itself when the last milestone is paid out.
        if (Escrow::where('project_id', $project->id)->where('status', '!=', Escrow::COMPLETED)->exists()) {
            throw new ApiException(__('payments.escrow_incomplete'), 'escrow_incomplete');
        }

        $project->update(['status' => Project::STATUS_COMPLETED]);

        return $project;
    }

    /**
     * Decline every offer still pending on the project and tell each offerer.
     */
    private function declinePending(Project $project, User $owner): void
    {
        $project->proposals()->where('status', Proposal::STATUS_PENDING)->with('user')->get()
            ->each(function (Proposal $pending) use ($project, $owner) {
                $pending->update(['status' => Proposal::STATUS_DECLINED]);
                $pending->user->notify(new ProposalActivity(ProposalActivity::DECLINED, $owner, $project, $pending));
            });
    }

    /**
     * The team an offer is made for: published, led by the offerer, and only on projects.
     */
    private function teamFor(Project $project, User $offerer, int $teamId): Team
    {
        $team = Team::findOrFail($teamId);

        if ($team->owner_id !== $offerer->id) {
            throw new ApiException(__('teams.not_yours'), 'team_not_yours');
        }

        if (! $team->isActive()) {
            throw new ApiException(__('teams.not_active'), 'team_not_active');
        }

        if ($project->type !== Project::TYPE_PROJECT) {
            throw new ApiException(__('teams.projects_only'), 'team_projects_only');
        }

        return $team;
    }

    /**
     * The rules for sending an offer: the project is open and not the offerer's own, the offerer has a profile of the
     * right type (freelancer for projects, influencer for requests) and has not offered on it yet.
     */
    private function assertCanOffer(Project $project, User $offerer): void
    {
        $this->assertProjectOpen($project);

        if ($project->user_id === $offerer->id) {
            throw new ApiException(__('proposals.own_project'), 'own_project');
        }

        $profile = $offerer->profile()->first();

        if (! $profile) {
            throw new ApiException(__('proposals.profile_required'), 'profile_required');
        }

        // Projects are for freelancers, influencer requests for influencers.
        $expected = $project->type === Project::TYPE_PROJECT ? Profile::TYPE_FREELANCER : Profile::TYPE_INFLUENCER;

        if ($profile->type !== $expected) {
            throw new ApiException(__("proposals.profile_type_mismatch.{$project->type}"), 'profile_type_mismatch');
        }

        if ($project->proposals()->where('user_id', $offerer->id)->exists()) {
            throw new ApiException(__('proposals.already_offered'), 'already_offered');
        }
    }

    /**
     * Refuse when the project no longer takes offers.
     */
    private function assertProjectOpen(Project $project): void
    {
        if (! $project->isOpen()) {
            throw new ApiException(__('proposals.project_not_open'), 'project_not_open');
        }
    }

    /**
     * Refuse when the offer was already accepted, declined or withdrawn.
     */
    private function assertPending(Proposal $proposal): void
    {
        if (! $proposal->isPending()) {
            throw new ApiException(__('proposals.proposal_not_pending'), 'proposal_not_pending');
        }
    }
}
