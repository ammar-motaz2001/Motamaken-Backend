<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $owner_id
 * @property string $title
 * @property string|null $description
 * @property string $target_type
 * @property int $target_id
 * @property string $currency
 * @property int $budget_cents
 * @property int $spent_cents
 * @property int $cost_per_visit_cents
 * @property float $fee_percent
 * @property string $status
 * @property array<array-key, mixed> $filters
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $owner
 * @property-read Collection<int, CampaignPromoter> $promoters
 * @property-read int|null $promoters_count
 * @property-read Collection<int, CampaignVisit> $visits
 * @property-read int|null $visits_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Campaign newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Campaign newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Campaign query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Campaign whereBudgetCents($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Campaign whereClosedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Campaign whereCostPerVisitCents($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Campaign whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Campaign whereCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Campaign whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Campaign whereEndsAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Campaign whereFeePercent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Campaign whereFilters($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Campaign whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Campaign whereOwnerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Campaign whereSpentCents($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Campaign whereStartsAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Campaign whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Campaign whereTargetId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Campaign whereTargetType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Campaign whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Campaign whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
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

    /**
     * Money columns are whole cents, the fee a percentage, filters an array and the schedule dates.
     */
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
     * The user who pays for the campaign.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * The users who joined the campaign to share its link.
     *
     * @return HasMany<CampaignPromoter, $this>
     */
    public function promoters(): HasMany
    {
        return $this->hasMany(CampaignPromoter::class);
    }

    /**
     * Every visit counted through a promoter's link, valid or rejected.
     *
     * @return HasMany<CampaignVisit, $this>
     */
    public function visits(): HasMany
    {
        return $this->hasMany(CampaignVisit::class);
    }

    /**
     * Budget not spent yet, never below zero.
     */
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

    /**
     * Whether the campaign has finished, completed or cancelled, and takes no more visits.
     */
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

    /**
     * The platform's cut of one visit, rounded to the nearest cent.
     */
    public function feeCents(): int
    {
        return intdiv($this->cost_per_visit_cents * (int) round($this->fee_percent * 100) + 5000, 10000);
    }
}
