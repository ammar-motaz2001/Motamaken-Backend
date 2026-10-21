<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property string|null $job_title
 * @property string|null $bio
 * @property float $rating
 * @property int $reviews_count
 * @property int|null $country_id
 * @property int|null $city_id
 * @property bool $is_featured
 * @property Carbon|null $featured_until
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property array<array-key, mixed>|null $skills
 * @property array<array-key, mixed>|null $languages
 * @property array<array-key, mixed>|null $experiences
 * @property array<array-key, mixed>|null $educations
 * @property array<array-key, mixed>|null $certificates
 * @property array<array-key, mixed>|null $channels
 * @property-read City|null $city
 * @property-read Country|null $country
 * @property-read Collection<int, PortfolioItem> $portfolio
 * @property-read int|null $portfolio_count
 * @property-read User $user
 *
 * @method static \Database\Factories\ProfileFactory factory($count = null, $state = [])
 * @method static Builder<static>|Profile featured()
 * @method static Builder<static>|Profile newModelQuery()
 * @method static Builder<static>|Profile newQuery()
 * @method static Builder<static>|Profile query()
 * @method static Builder<static>|Profile whereBio($value)
 * @method static Builder<static>|Profile whereCertificates($value)
 * @method static Builder<static>|Profile whereChannels($value)
 * @method static Builder<static>|Profile whereCityId($value)
 * @method static Builder<static>|Profile whereCountryId($value)
 * @method static Builder<static>|Profile whereCreatedAt($value)
 * @method static Builder<static>|Profile whereEducations($value)
 * @method static Builder<static>|Profile whereExperiences($value)
 * @method static Builder<static>|Profile whereFeaturedUntil($value)
 * @method static Builder<static>|Profile whereId($value)
 * @method static Builder<static>|Profile whereIsFeatured($value)
 * @method static Builder<static>|Profile whereJobTitle($value)
 * @method static Builder<static>|Profile whereLanguages($value)
 * @method static Builder<static>|Profile whereRating($value)
 * @method static Builder<static>|Profile whereReviewsCount($value)
 * @method static Builder<static>|Profile whereSkills($value)
 * @method static Builder<static>|Profile whereType($value)
 * @method static Builder<static>|Profile whereUpdatedAt($value)
 * @method static Builder<static>|Profile whereUserId($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['user_id', 'type', 'job_title', 'bio', 'rating', 'reviews_count', 'country_id', 'city_id', 'is_featured', 'featured_until', 'skills', 'languages', 'experiences', 'educations', 'certificates', 'channels'])]
class Profile extends Model
{
    use HasFactory;

    /**
     * The same defaults as the database, so a model created a moment ago looks like one loaded later.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['type' => 'freelancer', 'rating' => 0, 'reviews_count' => 0, 'is_featured' => false];

    public const TYPE_FREELANCER = 'freelancer';

    public const TYPE_INFLUENCER = 'influencer';

    /**
     * Rating and counters become numbers, the featured end a date, and the list fields (skills, languages, experience...) arrays.
     */
    protected function casts(): array
    {
        return [
            'rating' => 'float',
            'reviews_count' => 'integer',
            'is_featured' => 'boolean',
            'featured_until' => 'datetime',
            'skills' => 'array',
            'languages' => 'array',
            'experiences' => 'array',
            'educations' => 'array',
            'certificates' => 'array',
            'channels' => 'array',
        ];
    }

    /**
     * The account the profile belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The country the user lives in.
     *
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * The city the user lives in.
     *
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * Profiles in the homepage "Featured profiles" slider.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true)
            ->where(fn ($query) => $query->whereNull('featured_until')->orWhere('featured_until', '>', now()));
    }

    /**
     * The portfolio items, in the order the user arranged them.
     *
     * @return HasMany<PortfolioItem, $this>
     */
    public function portfolio(): HasMany
    {
        return $this->hasMany(PortfolioItem::class)->orderBy('position')->orderBy('id');
    }
}
