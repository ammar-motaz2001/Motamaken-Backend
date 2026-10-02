<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Bell notifications about offers: `received` (to the project owner), `accepted` and `declined` (to the offerer).
 */
class ProposalActivity extends Notification
{
    public const RECEIVED = 'received';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    /**
     * @param  User  $actor  The person the notification is about: who made the offer, or who answered it.
     */
    public function __construct(
        private readonly string $event,
        private readonly User $actor,
        private readonly Project $project,
        private readonly Proposal $proposal,
    ) {
        $this->id = (string) Str::orderedUuid();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => "proposal.{$this->event}",
            'user' => [
                'id' => $this->actor->id,
                'fullName' => $this->actor->full_name,
                'avatarUrl' => $this->actor->avatar_url,
            ],
            'projectId' => $this->project->id,
            'projectTitle' => $this->project->title,
            'proposalId' => $this->proposal->id,
        ];
    }
}
