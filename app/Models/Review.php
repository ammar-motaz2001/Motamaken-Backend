<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'reviewer_id', 'reviewee_id', 'rating', 'comment'])]
class Review extends Model
{
    protected function casts(): array
    {
        return ['rating' => 'integer', 'hidden_at' => 'datetime'];
    }

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
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewee_id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
