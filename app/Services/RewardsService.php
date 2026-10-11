<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\PaymentMethod;
use App\Models\PointTransaction;
use App\Models\Project;
use App\Models\Promotion;
use App\Models\Proposal;
use App\Models\Review;
use App\Models\User;
use App\Models\UserMedal;
use App\Notifications\ActivityNotification;
use Illuminate\Support\Facades\DB;

/**
 * Points and medals ("Points & gifts", "Medals system"). Every change to users.points goes through here so the history
 * explains the balance.
 */
class RewardsService
{
    /**
     * Grant points for an action from config('engagement.points'). Actions marked `once`, and any award given a `key`,
     * happen at most once per user. Returns null when it was already granted.
     *
     * @param  array<string, mixed>  $meta
     */
    public function award(User $user, string $reason, ?string $key = null, array $meta = [], ?int $amount = null): ?PointTransaction
    {
        $rule = config("engagement.points.{$reason}");
        $amount ??= (int) ($rule['amount'] ?? 0);
        $key ??= ($rule['once'] ?? false) ? $reason : null;

        if ($amount <= 0 || ($key && PointTransaction::where(['user_id' => $user->id, 'key' => $key])->exists())) {
            return null;
        }

        $transaction = DB::transaction(function () use ($user, $reason, $key, $meta, $amount) {
            $transaction = PointTransaction::create(['user_id' => $user->id, 'amount' => $amount, 'reason' => $reason, 'key' => $key, 'meta' => $meta ?: null]);
            $user->increment('points', $amount);

            return $transaction;
        });

        $user->notify(new ActivityNotification('points.earned', null, ['points' => $amount, 'reason' => __("rewards.reasons.{$reason}")]));

        return $transaction;
    }

    /**
     * Spend points (prize-draw tickets). Refuses with `insufficient_points` when the balance is too low.
     *
     * @param  array<string, mixed>  $meta
     */
    public function spend(User $user, int $amount, string $reason, array $meta = []): PointTransaction
    {
        return DB::transaction(function () use ($user, $amount, $reason, $meta) {
            $balance = (int) User::whereKey($user->id)->lockForUpdate()->value('points');

            if ($balance < $amount) {
                throw new ApiException(__('rewards.insufficient_points', ['points' => $balance]), 'insufficient_points');
            }

            User::whereKey($user->id)->decrement('points', $amount);
            $user->points = $balance - $amount;

            return PointTransaction::create(['user_id' => $user->id, 'amount' => -$amount, 'reason' => $reason, 'meta' => $meta ?: null]);
        });
    }

    /**
     * The numbers medals are measured on.
     *
     * @return array<string, int>
     */
    public function metrics(User $user): array
    {
        $hired = Proposal::where('user_id', $user->id)->where('status', 'accepted')->pluck('project_id');

        return [
            'identity_verified' => (int) ($user->kyc_status === 'approved'),
            'payment_methods' => PaymentMethod::where('user_id', $user->id)->count(),
            'completed_jobs' => Project::whereIn('id', $hired)->where('status', Project::STATUS_COMPLETED)->count(),
            'five_star_reviews' => Review::where('reviewee_id', $user->id)->where('rating', 5)->count(),
            'completed_projects' => Project::where('user_id', $user->id)->where('status', Project::STATUS_COMPLETED)->count(),
            'promotions' => Promotion::where('user_id', $user->id)->count(),
            'referrals' => User::where('referred_by', $user->id)->where(fn ($q) => $q->whereNotNull('email_verified_at')->orWhereNotNull('phone_verified_at'))->count(),
        ];
    }

    /**
     * Every medal with the user's progress and when it was earned.
     *
     * @return list<array{code: string, current: int, target: int, earned: bool, awardedAt: mixed}>
     */
    public function medals(User $user): array
    {
        $metrics = $this->metrics($user);
        $earned = UserMedal::where('user_id', $user->id)->pluck('awarded_at', 'code');

        return collect(config('engagement.medals'))->map(fn (array $medal) => [
            'code' => $medal['code'],
            'current' => min($metrics[$medal['metric']] ?? 0, $medal['target']),
            'target' => $medal['target'],
            'earned' => $earned->has($medal['code']),
            'awardedAt' => $earned->get($medal['code']),
        ])->values()->all();
    }

    /**
     * Award the medals the user has reached and not received yet.
     *
     * @return list<string> The codes awarded now.
     */
    public function syncMedals(User $user): array
    {
        $new = [];

        foreach ($this->medals($user) as $medal) {
            if (! $medal['earned'] && $medal['current'] >= $medal['target']) {
                UserMedal::firstOrCreate(['user_id' => $user->id, 'code' => $medal['code']], ['awarded_at' => now()]);
                $user->notify(new ActivityNotification("medal.{$medal['code']}", null, []));
                $new[] = $medal['code'];
            }
        }

        return $new;
    }

    /**
     * First time an email or phone is confirmed: welcome points, and the referrer's reward.
     */
    public function onVerified(User $user): void
    {
        $this->award($user, 'welcome', amount: (int) config('auth_flow.welcome_points'));

        if ($user->referred_by && $referrer = User::find($user->referred_by)) {
            $this->award($referrer, 'referral', "referral:{$user->id}", ['userId' => $user->id]);
            $this->syncMedals($referrer);
        }
    }

    public function onProjectCompleted(Project $project): void
    {
        $freelancerId = $project->accepted_proposal_id ? Proposal::whereKey($project->accepted_proposal_id)->value('user_id') : null;

        foreach (User::whereIn('id', array_filter([$project->user_id, $freelancerId]))->get() as $user) {
            $this->award($user, 'project_completed', "project_completed:{$project->id}", ['projectId' => $project->id]);
            $this->syncMedals($user);
        }
    }

    /**
     * Grant the one-time "profile completed" points once the profile has a photo, about text, 3 skills and a portfolio item.
     */
    public function checkProfileCompleted(User $user): void
    {
        $profile = $user->profile()->withCount('portfolio')->first();

        if ($profile && $user->avatar_path && filled($profile->bio) && count($profile->skills ?? []) >= 3 && $profile->portfolio_count > 0) {
            $this->award($user, 'profile_completed');
        }
    }
}
