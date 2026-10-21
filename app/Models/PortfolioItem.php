<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $profile_id
 * @property string $title
 * @property string|null $description
 * @property string $image_path
 * @property string|null $link
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read mixed $image_url
 * @property-read Profile $profile
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PortfolioItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PortfolioItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PortfolioItem query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PortfolioItem whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PortfolioItem whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PortfolioItem whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PortfolioItem whereImagePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PortfolioItem whereLink($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PortfolioItem wherePosition($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PortfolioItem whereProfileId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PortfolioItem whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PortfolioItem whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['profile_id', 'title', 'description', 'image_path', 'link', 'position'])]
class PortfolioItem extends Model
{
    /**
     * Public URL of the portfolio image.
     *
     * @return Attribute<string, never>
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn () => Storage::disk('public')->url($this->image_path));
    }

    /**
     * The profile showing this work.
     *
     * @return BelongsTo<Profile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
