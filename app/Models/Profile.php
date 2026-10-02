<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'type', 'job_title', 'bio', 'rating', 'reviews_count', 'country_id', 'city_id', 'is_featured', 'featured_until'])]
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

    protected function casts(): array
    {
        return [
            'rating' => 'float',
            'reviews_count' => 'integer',
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
}
