<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $type
 * @property string $slug
 * @property string $name_en
 * @property string $name_ar
 * @property string|null $icon
 * @property int $sort_order
 * @property int|null $parent_id
 * @property bool $is_active
 * @property-read mixed $name
 *
 * @method static \Database\Factories\CategoryFactory factory($count = null, $state = [])
 * @method static Builder<static>|Category newModelQuery()
 * @method static Builder<static>|Category newQuery()
 * @method static Builder<static>|Category ofType(string $type)
 * @method static Builder<static>|Category query()
 * @method static Builder<static>|Category whereIcon($value)
 * @method static Builder<static>|Category whereId($value)
 * @method static Builder<static>|Category whereIsActive($value)
 * @method static Builder<static>|Category whereNameAr($value)
 * @method static Builder<static>|Category whereNameEn($value)
 * @method static Builder<static>|Category whereParentId($value)
 * @method static Builder<static>|Category whereSlug($value)
 * @method static Builder<static>|Category whereSortOrder($value)
 * @method static Builder<static>|Category whereType($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['type', 'slug', 'name_en', 'name_ar', 'icon', 'sort_order', 'parent_id', 'is_active'])]
class Category extends Model
{
    use HasFactory, HasLocalizedName;

    /**
     * is_active becomes a boolean.
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** Project categories, e.g. "Website, IT & Software". */
    public const TYPE_PROJECT = 'project';

    /** Influencer platforms, e.g. "Instagram". */
    public const TYPE_INFLUENCER = 'influencer';

    public $timestamps = false;

    /**
     * Keep the categories of one type: project categories or influencer platforms.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }
}
