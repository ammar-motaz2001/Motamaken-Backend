<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Escrow;
use App\Models\Proposal;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\ActivityNotification;
use Illuminate\Support\Facades\DB;

/**
 * Freelancers who take projects together and split the pay. The lead sets each member's share and the lead keeps
 * whatever is left, so shares always add up to 100%. A member's share only counts once they accept the invitation, and
 * a team can send offers only after it is published.
 */
class TeamService
{
    public const INVITATION_DAYS = 7;

    /**
     * Create a draft team with its lead as the first member, accepted and holding the full share.
     */
    public function create(User $lead, string $name, ?string $description): Team
    {
        return DB::transaction(function () use ($lead, $name, $description) {
            $team = Team::create(['owner_id' => $lead->id, 'name' => $name, 'description' => $description]);
            $team->members()->create([
                'user_id' => $lead->id, 'is_lead' => true, 'status' => TeamMember::ACCEPTED,
                'share_bp' => Team::FULL_SHARE_BP, 'responded_at' => now(),
            ]);

            return $team;
        });
    }

    /**
     * Invite a freelancer to a draft team with a share. A declined or expired member can be invited again; the team size
     * and the shares are checked, the lead's share is rebalanced and the invitee is told.
     */
    public function invite(Team $team, User $invitee, ?string $roleTitle, int $shareBp): TeamMember
    {
        $this->assertEditable($team);

        if (! $invitee->profile()->where('type', 'freelancer')->exists()) {
            throw new ApiException(__('teams.member_needs_profile'), 'member_needs_profile');
        }

        $member = DB::transaction(function () use ($team, $invitee, $roleTitle, $shareBp) {
            $existing = $team->members()->where('user_id', $invitee->id)->first();

            if ($existing && ! in_array($existing->status, [TeamMember::DECLINED, TeamMember::EXPIRED], true)) {
                throw new ApiException(__('teams.already_member'), 'already_member');
            }

            if (! $existing && $team->members()->count() >= Team::MAX_MEMBERS) {
                throw new ApiException(__('teams.too_many_members', ['max' => Team::MAX_MEMBERS]), 'team_full');
            }

            $member = $existing ?? new TeamMember(['team_id' => $team->id, 'user_id' => $invitee->id]);
            $this->assertShareFits($team, $shareBp, $existing);
            $member->fill(['role_title' => $roleTitle, 'share_bp' => $shareBp])->save();
            $this->reinvite($member);
            $this->rebalance($team);

            return $member;
        });

        $this->notifyInvited($member);

        return $member;
    }

    /**
     * Change a member's role or share. A different share needs their approval again.
     */
    public function updateMember(Team $team, TeamMember $member, ?string $roleTitle, ?int $shareBp): TeamMember
    {
        $this->assertEditable($team);

        if ($member->is_lead) {
            throw new ApiException(__('teams.lead_is_automatic'), 'lead_share_is_automatic');
        }

        DB::transaction(function () use ($team, $member, $roleTitle, $shareBp) {
            if ($roleTitle !== null) {
                $member->role_title = $roleTitle;
            }

            $changed = $shareBp !== null && $shareBp !== $member->share_bp;

            if ($changed) {
                $this->assertShareFits($team, $shareBp, $member);
                $member->share_bp = $shareBp;
                $this->reinvite($member);
            }

            $member->save();
            $this->rebalance($team);

            if ($changed) {
                $this->notifyInvited($member);
            }
        });

        return $member->refresh();
    }

    /**
     * Remove a member from a draft team (never the lead); their share goes back to the lead.
     */
    public function removeMember(Team $team, TeamMember $member): void
    {
        $this->assertEditable($team);

        if ($member->is_lead) {
            throw new ApiException(__('teams.cannot_remove_lead'), 'cannot_remove_lead');
        }

        DB::transaction(function () use ($team, $member) {
            $member->delete();
            $this->rebalance($team);
        });
    }

    /**
     * Send an invitation again after it expired or was declined.
     */
    public function resend(Team $team, TeamMember $member): TeamMember
    {
        $this->assertEditable($team);

        if ($member->status === TeamMember::ACCEPTED) {
            throw new ApiException(__('teams.invitation_answered'), 'invitation_answered');
        }

        $this->reinvite($member);
        $member->save();
        $this->notifyInvited($member);

        return $member;
    }

    /**
     * A member leaves. In a published team that takes it back to a draft, unless it is working on a project.
     */
    public function leave(Team $team, TeamMember $member): void
    {
        if ($member->is_lead) {
            throw new ApiException(__('teams.cannot_remove_lead'), 'cannot_remove_lead');
        }

        $this->assertNotInUse($team);

        DB::transaction(function () use ($team, $member) {
            $member->delete();
            $team->update(['status' => Team::DRAFT, 'published_at' => null]);
            $this->rebalance($team);
        });

        $this->notify($team->owner, 'team.left', $member->user, $team);
    }

    /**
     * The invitee accepts a pending invitation; the lead is told.
     */
    public function accept(TeamMember $member): TeamMember
    {
        $this->assertAnswerable($member);
        $member->update(['status' => TeamMember::ACCEPTED, 'responded_at' => now()]);
        $this->notify($member->team->owner, 'team.accepted', $member->user, $member->team);

        return $member;
    }

    /**
     * The invitee declines a pending invitation; the lead is told.
     */
    public function decline(TeamMember $member): TeamMember
    {
        $this->assertAnswerable($member);
        $member->update(['status' => TeamMember::DECLINED, 'responded_at' => now()]);
        $this->notify($member->team->owner, 'team.declined', $member->user, $member->team);

        return $member;
    }

    /**
     * The publish gate: a team can send offers once it has at least two members, all of them accepted.
     */
    public function publish(Team $team): Team
    {
        $blockers = $team->blockers();

        if ($blockers !== []) {
            throw new ApiException(__('teams.not_ready'), 'team_not_ready', 422, ['blockers' => $blockers]);
        }

        $team->update(['status' => Team::ACTIVE, 'published_at' => now()]);

        foreach ($team->members()->where('is_lead', false)->with('user')->get() as $member) {
            $this->notify($member->user, 'team.published', $team->owner, $team);
        }

        return $team;
    }

    /**
     * Take a published team back to a draft to change it. Not while it is working on a project.
     */
    public function unpublish(Team $team): Team
    {
        $this->assertNotInUse($team);
        $team->update(['status' => Team::DRAFT, 'published_at' => null]);

        return $team;
    }

    /**
     * Close a team for good. Not while it is working on a project.
     */
    public function disband(Team $team): Team
    {
        $this->assertNotInUse($team);
        $team->update(['status' => Team::DISBANDED, 'published_at' => null]);
        // Unanswered invitations die with the team, so invitees no longer see them.
        $team->members()->where('status', TeamMember::INVITED)->update(['status' => TeamMember::EXPIRED, 'responded_at' => now()]);

        return $team;
    }

    /**
     * Invitations nobody answered in time expire; the lead and the invitee are told. Run by the scheduler.
     *
     * @return int How many expired.
     */
    public function expireInvitations(): int
    {
        $expired = TeamMember::with(['team.owner', 'user'])->where('status', TeamMember::INVITED)->where('expires_at', '<', now())->get();

        foreach ($expired as $member) {
            $member->update(['status' => TeamMember::EXPIRED]);
            $this->notify($member->team->owner, 'team.expired', $member->user, $member->team);
            $this->notify($member->user, 'team.expired_you', null, $member->team);
        }

        return $expired->count();
    }

    /**
     * Whether the team is the one behind a project that is not finished.
     */
    public function isInUse(Team $team): bool
    {
        return Proposal::where('team_id', $team->id)->where('status', Proposal::STATUS_ACCEPTED)
            ->whereHas('project', fn ($query) => $query->where('status', 'in_progress'))->exists()
            || Escrow::whereIn('proposal_id', Proposal::where('team_id', $team->id)->select('id'))->where('status', '!=', Escrow::COMPLETED)->exists();
    }

    /**
     * The lead keeps whatever the others do not take.
     */
    private function rebalance(Team $team): void
    {
        $others = (int) $team->members()->where('is_lead', false)->sum('share_bp');
        $team->members()->where('is_lead', true)->update(['share_bp' => Team::FULL_SHARE_BP - $others]);
    }

    /**
     * A share is at least 1%, and the others together must leave the lead at least 1%.
     */
    private function assertShareFits(Team $team, int $shareBp, ?TeamMember $replacing): void
    {
        $others = (int) $team->members()->where('is_lead', false)
            ->when($replacing?->exists, fn ($query) => $query->where('id', '!=', $replacing->id))->sum('share_bp');
        $room = Team::FULL_SHARE_BP - Team::MIN_SHARE_BP - $others;

        if ($shareBp < Team::MIN_SHARE_BP || $shareBp > $room) {
            throw new ApiException(__('teams.share_out_of_range', ['max' => $room / 100]), 'share_out_of_range');
        }
    }

    /**
     * Put the member back to invited, with a new invitation window.
     */
    private function reinvite(TeamMember $member): void
    {
        $member->fill([
            'status' => TeamMember::INVITED, 'invited_at' => now(), 'responded_at' => null,
            'expires_at' => now()->addDays(self::INVITATION_DAYS),
        ])->save();
    }

    /**
     * Members and shares can only change while the team is a draft.
     */
    private function assertEditable(Team $team): void
    {
        if ($team->status !== Team::DRAFT) {
            throw new ApiException(__('teams.not_draft'), 'team_not_draft');
        }
    }

    /**
     * Refuse while the team is behind a project that is not finished.
     */
    private function assertNotInUse(Team $team): void
    {
        if ($this->isInUse($team)) {
            throw new ApiException(__('teams.in_use'), 'team_in_use');
        }
    }

    /**
     * Only an invitation that is still waiting and has not expired can be accepted or declined.
     */
    private function assertAnswerable(TeamMember $member): void
    {
        if ($member->status !== TeamMember::INVITED) {
            throw new ApiException(__('teams.invitation_answered'), 'invitation_answered');
        }

        if (! $member->isAnswerable()) {
            throw new ApiException(__('teams.invitation_expired'), 'invitation_expired');
        }
    }

    /**
     * Tell the invitee about the invitation, with their share and how many days they have to answer.
     */
    private function notifyInvited(TeamMember $member): void
    {
        $member->loadMissing(['team.owner', 'user']);
        $member->user->notify(new ActivityNotification('team.invited', $member->team->owner, [
            'teamId' => $member->team_id, 'team' => $member->team->name, 'memberId' => $member->id,
            'share' => $member->share_bp / 100, 'days' => self::INVITATION_DAYS,
        ]));
    }

    /**
     * Send a team notification carrying the team id and name.
     */
    private function notify(User $to, string $type, ?User $actor, Team $team): void
    {
        $to->notify(new ActivityNotification($type, $actor, ['teamId' => $team->id, 'team' => $team->name]));
    }
}
