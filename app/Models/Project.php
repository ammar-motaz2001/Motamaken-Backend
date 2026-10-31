<?php

namespace App\Models;

use App\Services\RewardsService;
use App\Support\LikeSearch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property int $category_id
 * @property string $title
 * @property string $description
 * @property int $budget_min
 * @property int $budget_max
 * @property string $currency
 * @property int|null $country_id
 * @property int|null $city_id
 * @property string $status
 * @property bool $is_featured
 * @property Carbon|null $featured_until
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $accepted_proposal_id
 * @property Carbon|null $hidden_at
 * @property string|null $hidden_reason
 * @property-read Category $category
 * @property-read City|null $city
 * @property-read Country|null $country
 * @property-read Collection<int, Proposal> $proposals
 * @property-read int|null $proposals_count
 * @property-read User $user
 * @property-read Proposal|null $viewerProposal
 *
 * @method static Builder<static>|Project distinctive()
 * @method static \Database\Factories\ProjectFactory factory($count = null, $state = [])
 * @method static Builder<static>|Project filter(array $filters)
 * @method static Builder<static>|Project newModelQuery()
 * @method static Builder<static>|Project newQuery()
 * @method static Builder<static>|Project open()
 * @method static Builder<static>|Project query()
 * @method static Builder<static>|Project whereAcceptedProposalId($value)
 * @method static Builder<static>|Project whereBudgetMax($value)
 * @method static Builder<static>|Project whereBudgetMin($value)
 * @method static Builder<static>|Project whereCategoryId($value)
 * @method static Builder<static>|Project whereCityId($value)
 * @method static Builder<static>|Project whereCountryId($value)
 * @method static Builder<static>|Project whereCreatedAt($value)
 * @method static Builder<static>|Project whereCurrency($value)
 * @method static Builder<static>|Project whereDescription($value)
 * @method static Builder<static>|Project whereFeaturedUntil($value)
 * @method static Builder<static>|Project whereHiddenAt($value)
 * @method static Builder<static>|Project whereHiddenReason($value)
 * @method static Builder<static>|Project whereId($value)
 * @method static Builder<static>|Project whereIsFeatured($value)
 * @method static Builder<static>|Project whereStatus($value)
 * @method static Builder<static>|Project whereTitle($value)
 * @method static Builder<static>|Project whereType($value)
 * @method static Builder<static>|Project whereUpdatedAt($value)
 * @method static Builder<static>|Project whereUserId($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['user_id', 'type', 'category_id', 'title', 'description', 'budget_min', 'budget_max', 'currency', 'country_id', 'city_id', 'status', 'accepted_proposal_id', 'is_featured', 'featured_until', 'hidden_at', 'hidden_reason'])]
class Project extends Model
{
    use HasFactory;

    /**
     * The same defaults as the database, so a model created a moment ago looks like one loaded later.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['type' => 'project', 'currency' => 'USD', 'status' => 'open', 'is_featured' => false];

    public const TYPE_PROJECT = 'project';

    public const TYPE_INFLUENCER_REQUEST = 'influencer_request';

    /** Accepting offers: shown on the homepage and in search. */
    /** Saved by the owner and not published yet: visible to nobody else. */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_OPEN = 'open';

    /** An offer was accepted and the work is under way. */
    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    /** Cancelled by the owner before any offer was accepted. */
    public const STATUS_CLOSED = 'closed';

    /**
     * Budget bounds become integers, is_featured a boolean, the featured and hidden times dates.
     */
    protected function casts(): array
    {
        return [
            'budget_min' => 'integer',
            'budget_max' => 'integer',
            'is_featured' => 'boolean',
            'featured_until' => 'datetime',
            'hidden_at' => 'datetime',
        ];
    }

    /**
     * The client who posted the project.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The category the project is listed under.
     *
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * The country the project is for.
     *
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * The city the project is for.
     *
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * The proposals freelancers and teams sent for it.
     *
     * @return HasMany<Proposal, $this>
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }

    /**
     * Set by the project page for a logged-in visitor: their own offer, or null.
     *
     * @return HasOne<Proposal, $this>
     */
    public function viewerProposal(): HasOne
    {
        return $this->hasOne(Proposal::class);
    }

    /**
     * Whether the project takes proposals: open and not hidden by a moderator.
     */
    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN && $this->hidden_at === null;
    }

    /**
     * The owner can still change or delete it.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_OPEN], true);
    }

    /**
     * Awards the completion rewards when the status changes to completed.
     */
    protected static function booted(): void
    {
        // Both sides earn points (and maybe medals) when a project is completed, however it got there.
        static::updated(function (self $project) {
            if ($project->wasChanged('status') && $project->status === self::STATUS_COMPLETED) {
                app(RewardsService::class)->onProjectCompleted($project);
            }
        });
    }

    /**
     * Projects visible on the site: open and not hidden by a moderator.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('projects.status', self::STATUS_OPEN)->whereNull('projects.hidden_at');
    }

    /**
     * Projects in the homepage "Distinctive projects" slider.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDistinctive(Builder $query): Builder
    {
        return $query->where('projects.is_featured', true)->whereNull('projects.hidden_at')
            ->where(fn ($query) => $query->whereNull('projects.featured_until')->orWhere('projects.featured_until', '>', now()));
    }

    /**
     * The filters shared by the project list and the search bar.
     *
     * @param  Builder<self>  $query
     * @param  array{type?: string, category?: string, q?: string, countryId?: int, cityId?: int, budgetMin?: int, budgetMax?: int, currency?: string}  $filters
     * @return Builder<self>
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('projects.type', $type))
            ->when($filters['category'] ?? null, fn ($query, $slug) => $query->whereHas(
                'category', fn ($query) => $query->where('slug', $slug),
            ))
            ->when($filters['q'] ?? null, fn ($query, $term) => LikeSearch::apply(
                $query, ['projects.title', 'projects.description'], $term,
            ))
            ->when($filters['countryId'] ?? null, fn ($query, $id) => $query->where('projects.country_id', $id))
            ->when($filters['cityId'] ?? null, fn ($query, $id) => $query->where('projects.city_id', $id))
            // Budgets that lie inside the requested range (from its minimum up to its maximum), compared in US dollars:
            // the filter is in the visitor's currency (`currency`, US dollars when absent) and each project in its own.
            // A tiny tolerance absorbs rounding of the converted amounts.
            ->when($filters['budgetMin'] ?? null, fn ($query, $min) => $query->whereRaw(self::inUsd('projects.budget_min').' >= cast(? as double precision)', [self::toUsd((float) $min, $filters['currency'] ?? null) - 0.01]))
            ->when($filters['budgetMax'] ?? null, fn ($query, $max) => $query->whereRaw(self::inUsd('projects.budget_max').' <= cast(? as double precision)', [self::toUsd((float) $max, $filters['currency'] ?? null) + 0.01]));
    }

    /**
     * SQL for a budget column converted to US dollars with the admins' exchange rates (units per dollar), so budgets in
     * different currencies sort and compare fairly.
     */
    public static function inUsd(string $column): string
    {
        $cases = collect(config('marketplace.currencies'))
            ->filter(fn (array $currency) => preg_match('/^[A-Z]{3}$/', $currency['code']) && (float) ($currency['rate'] ?? 0) > 0)
            ->map(fn (array $currency) => "when '{$currency['code']}' then ".((float) $currency['rate']))
            ->implode(' ');

        return "({$column} * 1.0 / case projects.currency {$cases} else 1 end)";
    }

    /**
     * An amount in a currency converted to US dollars (unknown currencies count as dollars).
     */
    public static function toUsd(float $amount, ?string $currency): float
    {
        $rate = (float) (collect(config('marketplace.currencies'))->firstWhere('code', $currency)['rate'] ?? 1);

        return $rate > 0 ? $amount / $rate : $amount;
    }
}
