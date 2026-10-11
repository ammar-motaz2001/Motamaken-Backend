<?php

namespace App\Models;

use App\Services\RewardsService;
use App\Support\LikeSearch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['user_id', 'type', 'category_id', 'title', 'description', 'budget_min', 'budget_max', 'currency', 'country_id', 'city_id', 'status', 'accepted_proposal_id', 'is_featured', 'featured_until'])]
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
    public const STATUS_OPEN = 'open';

    /** An offer was accepted and the work is under way. */
    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    /** Cancelled by the owner before any offer was accepted. */
    public const STATUS_CLOSED = 'closed';

    protected function casts(): array
    {
        return [
            'budget_min' => 'integer',
            'budget_max' => 'integer',
            'is_featured' => 'boolean',
            'featured_until' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
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

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
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

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('projects.status', self::STATUS_OPEN);
    }

    /**
     * Projects in the homepage "Distinctive projects" slider.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDistinctive(Builder $query): Builder
    {
        return $query->where('projects.is_featured', true)
            ->where(fn ($query) => $query->whereNull('projects.featured_until')->orWhere('projects.featured_until', '>', now()));
    }

    /**
     * The filters shared by the project list and the search bar.
     *
     * @param  Builder<self>  $query
     * @param  array{type?: string, category?: string, q?: string, countryId?: int, cityId?: int, budgetMin?: int, budgetMax?: int}  $filters
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
            // Budget ranges that overlap the requested range.
            ->when($filters['budgetMin'] ?? null, fn ($query, $min) => $query->where('projects.budget_max', '>=', $min))
            ->when($filters['budgetMax'] ?? null, fn ($query, $max) => $query->where('projects.budget_min', '<=', $max));
    }
}
