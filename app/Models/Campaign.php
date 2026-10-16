<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['owner_id', 'title', 'description', 'target_type', 'target_id', 'currency', 'budget_cents', 'spent_cents', 'cost_per_visit_cents', 'fee_percent', 'status', 'filters', 'starts_at', 'ends_at', 'closed_at'])]
class Campaign extends Model
{
    use HasFactory;

    public const DRAFT = 'draft';

    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    /** The budget ran out or the end date passed: the rest went back to the owner. */
    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const TARGET_PROJECT = 'project';

    public const TARGET_PROFILE = 'profile';

    /** The filtering rules a new campaign starts with. */
    public const DEFAULT_FILTERS = [
        // Turn away crawlers and scripts that say what they are.
        'block_bots' => true,
        // Count one visit per visitor in this many hours.
        'unique_window_hours' => 24,
        // Most paid visits one promoter can bring per hour, against bought traffic.
        'max_visits_per_promoter_per_hour' => 100,
        // Two-letter country codes to accept, or empty for anywhere. Needs a CDN that sends the visitor's country.
        'allowed_countries' => [],
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'draft', 'spent_cents' => 0];

    protected function casts(): array
    {
        return [
            'budget_cents' => 'integer',
            'spent_cents' => 'integer',
            'cost_per_visit_cents' => 'integer',
            'fee_percent' => 'float',
            'filters' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<CampaignPromoter, $this>
     */
    public function promoters(): HasMany
    {
        return $this->hasMany(CampaignPromoter::class);
    }

    /**
     * @return HasMany<CampaignVisit, $this>
     */
    public function visits(): HasMany
    {
        return $this->hasMany(CampaignVisit::class);
    }

    public function remainingCents(): int
    {
        return max(0, $this->budget_cents - $this->spent_cents);
    }

    /**
     * Whether it is taking visits right now.
     */
    public function isRunning(): bool
    {
        return $this->status === self::ACTIVE
            && (! $this->starts_at || $this->starts_at->isPast())
            && (! $this->ends_at || $this->ends_at->isFuture());
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::CANCELLED], true);
    }

    /**
     * The page on the website a link leads to.
     */
    public function targetPath(): string
    {
        return $this->target_type === self::TARGET_PROJECT ? "/projects/{$this->target_id}" : "/users/{$this->target_id}";
    }

    /**
     * What a promoter gets for one valid visit.
     */
    public function rewardCents(): int
    {
        return $this->cost_per_visit_cents - $this->feeCents();
    }

    public function feeCents(): int
    {
        return intdiv($this->cost_per_visit_cents * (int) round($this->fee_percent * 100) + 5000, 10000);
    }
}
