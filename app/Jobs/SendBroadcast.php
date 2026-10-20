<?php

namespace App\Jobs;

use App\Models\Broadcast;
use App\Models\User;
use App\Notifications\ActivityEmail;
use App\Notifications\ActivityNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers a staff announcement to its audience in batches: the bell for everyone, and email when chosen.
 */
class SendBroadcast implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public function __construct(public readonly Broadcast $broadcast) {}

    public function handle(): void
    {
        $broadcast = $this->broadcast;
        $params = [
            'broadcastId' => $broadcast->id,
            'titleEn' => $broadcast->title_en, 'titleAr' => $broadcast->title_ar,
            'bodyEn' => $broadcast->body_en, 'bodyAr' => $broadcast->body_ar,
        ];
        $email = in_array('email', $broadcast->channels ?? [], true);
        $count = 0;

        self::audience($broadcast->audience, $broadcast->target_user_id)->chunkById(500, function ($users) use ($params, $email, &$count) {
            foreach ($users as $user) {
                $notification = new ActivityNotification('admin.broadcast', null, $params);
                $user->notify($notification);

                if ($email && $user->email) {
                    $user->notify((new ActivityEmail($notification->toArray($user)))->locale($user->locale ?: config('app.locale')));
                }

                $count++;
            }
        });

        $broadcast->update(['recipients' => $count]);
    }

    /**
     * Who receives an announcement: active (not suspended) accounts of the chosen type.
     *
     * @return Builder<User>
     */
    public static function audience(string $audience, ?int $userId = null): Builder
    {
        return User::query()
            ->whereNull('suspended_at')
            ->when($audience === 'user', fn ($q) => $q->whereKey($userId))
            ->when($audience === 'clients', fn ($q) => $q->whereDoesntHave('profile'))
            ->when($audience === 'freelancers', fn ($q) => $q->whereHas('profile', fn ($p) => $p->where('type', 'freelancer')))
            ->when($audience === 'influencers', fn ($q) => $q->whereHas('profile', fn ($p) => $p->where('type', 'influencer')));
    }
}
