<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Medal;
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
     * Every medal with the user's progress and when it was earned. Medals the admins switched off are listed only for
     * those who already hold them.
     *
     * @return list<array{code: string, name: string, description: ?string, icon: ?string, current: int, target: int, earned: bool, awardedAt: mixed}>
     */
    public function medals(User $user): array
    {
        $metrics = $this->metrics($user);
        $earned = UserMedal::where('user_id', $user->id)->pluck('awarded_at', 'code');
        $ar = app()->getLocale() === 'ar';

        return Medal::orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn (Medal $medal) => $medal->is_active || $earned->has($medal->code))
            ->map(fn (Medal $medal) => [
                'code' => $medal->code,
                'name' => $medal->name(),
                'description' => ($ar ? $medal->description_ar : $medal->description_en)
                    ?? (__("rewards.medals.{$medal->code}.description") !== "rewards.medals.{$medal->code}.description" ? __("rewards.medals.{$medal->code}.description") : null),
                'icon' => $medal->icon,
                'current' => $earned->has($medal->code) ? $medal->target : min($medal->metric ? ($metrics[$medal->metric] ?? 0) : 0, $medal->target),
                'target' => $medal->target,
                'earned' => $earned->has($medal->code),
                'awardedAt' => $earned->get($medal->code),
            ])->values()->all();
    }

    /**
     * Award the active medals the user has reached and not received yet.
     *
     * @return list<string> The codes awarded now.
     */
    public function syncMedals(User $user): array
    {
        $new = [];
        $metrics = $this->metrics($user);
        $earned = UserMedal::where('user_id', $user->id)->pluck('code')->all();

        foreach (Medal::where('is_active', true)->whereNotNull('metric')->get() as $medal) {
            if (! in_array($medal->code, $earned, true) && ($metrics[$medal->metric] ?? 0) >= $medal->target) {
                $this->giveMedal($user, $medal);
                $new[] = $medal->code;
            }
        }

        return $new;
    }

    /**
     * Give a medal, automatically or by an admin (with the reason kept). Returns null when the user already holds it.
     */
    public function giveMedal(User $user, Medal $medal, ?User $admin = null, ?string $reason = null): ?UserMedal
    {
        $award = UserMedal::firstOrCreate(['user_id' => $user->id, 'code' => $medal->code], ['awarded_at' => now(), 'awarded_by' => $admin?->id, 'reason' => $reason]);

        if (! $award->wasRecentlyCreated) {
            return null;
        }

        $user->notify(new ActivityNotification("medal.{$medal->code}", null, ['medal' => $medal->name_en, 'medalAr' => $medal->name_ar]));

        return $award;
    }

    /**
     * Grant (positive) or take (negative) points by hand, with the reason in the history. Never below zero.
     */
    public function adjust(User $user, int $amount, string $note, User $admin): PointTransaction
    {
        return DB::transaction(function () use ($user, $amount, $note, $admin) {
            $balance = (int) User::whereKey($user->id)->lockForUpdate()->value('points');

            if ($balance + $amount < 0) {
                throw new ApiException(__('rewards.insufficient_points', ['points' => $balance]), 'insufficient_points');
            }

            User::whereKey($user->id)->increment('points', $amount);
            $user->points = $balance + $amount;

            $transaction = PointTransaction::create([
                'user_id' => $user->id, 'amount' => $amount, 'reason' => 'admin_adjustment', 'meta' => ['note' => $note], 'created_by' => $admin->id,
            ]);

            if ($amount > 0) {
                $user->notify(new ActivityNotification('points.earned', null, ['points' => $amount, 'reason' => $note]));
            }

            return $transaction;
        });
    }

    /**
     * First time an email or phone is confirmed: welcome points, and the referrer's reward. A referral that looks like
     * self-referral (see AffiliateService::suspicion) is held instead: no points or commissions until an admin approves.
     */
    public function onVerified(User $user): void
    {
        $this->award($user, 'welcome', amount: (int) config('auth_flow.welcome_points'));

        if ($user->referred_by && $referrer = User::find($user->referred_by)) {
            if (app(AffiliateService::class)->suspicion($user, countToday: true)) {
                $user->forceFill(['referral_held_at' => now()])->save();

                return;
            }
            $this->rewardReferral($user, $referrer);
        }
    }

    /**
     * Give the inviter their referral points for this account (once) and update their medals.
     */
    public function rewardReferral(User $invited, User $referrer): void
    {
        $this->award($referrer, 'referral', "referral:{$invited->id}", ['userId' => $invited->id]);
        $this->syncMedals($referrer);
    }

    /**
     * Award the one-time project completion points to the client and the hired freelancer, then update their medals.
     */
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
