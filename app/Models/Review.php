<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int $reviewer_id
 * @property int $reviewee_id
 * @property int $rating
 * @property string|null $comment
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $hidden_at
 * @property string|null $hidden_reason
 * @property int|null $hidden_by
 * @property-read Project $project
 * @property-read User $reviewee
 * @property-read User $reviewer
 *
 * @method static Builder<static>|Review newModelQuery()
 * @method static Builder<static>|Review newQuery()
 * @method static Builder<static>|Review query()
 * @method static Builder<static>|Review whereComment($value)
 * @method static Builder<static>|Review whereCreatedAt($value)
 * @method static Builder<static>|Review whereHiddenAt($value)
 * @method static Builder<static>|Review whereHiddenBy($value)
 * @method static Builder<static>|Review whereHiddenReason($value)
 * @method static Builder<static>|Review whereId($value)
 * @method static Builder<static>|Review whereProjectId($value)
 * @method static Builder<static>|Review whereRating($value)
 * @method static Builder<static>|Review whereRevieweeId($value)
 * @method static Builder<static>|Review whereReviewerId($value)
 * @method static Builder<static>|Review whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['project_id', 'reviewer_id', 'reviewee_id', 'rating', 'comment', 'reply', 'replied_at'])]
class Review extends Model
{
    /**
     * The rating becomes an integer, hidden_at a date.
     */
    protected function casts(): array
    {
        return ['rating' => 'integer', 'hidden_at' => 'datetime'];
    }

    /**
     * Adds the global scope that leaves out reviews hidden by a moderator.
     */
    protected static function booted(): void
    {
        // Reviews hidden by a moderator are gone from profiles, averages and medals. Admin screens lift the scope.
        static::addGlobalScope('visible', fn (Builder $query) => $query->whereNull('reviews.hidden_at'));
    }

    /**
     * Recompute a user's average and count on their profile (after a review is added, hidden or shown again).
     */
    public static function refreshStats(int $userId): void
    {
        $stats = static::where('reviewee_id', $userId)->selectRaw('avg(rating) as average, count(*) as total')->first();
        Profile::where('user_id', $userId)->update(['rating' => round((float) $stats->average, 1), 'reviews_count' => (int) $stats->total]);
    }

    /**
     * The user who wrote the review.
     *
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * The user being reviewed.
     *
     * @return BelongsTo<User, $this>
     */
    public function reviewee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewee_id');
    }

    /**
     * The completed project the review is about.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
