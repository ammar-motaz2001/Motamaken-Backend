<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $slug
 * @property string $name_en
 * @property string $name_ar
 * @property int|null $category_id
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Category|null $category
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Skill newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Skill newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Skill query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Skill whereCategoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Skill whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Skill whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Skill whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Skill whereNameAr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Skill whereNameEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Skill whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Skill whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Skill whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['slug', 'name_en', 'name_ar', 'category_id', 'is_active', 'sort_order'])]
class Skill extends Model
{
    /**
     * is_active becomes a boolean.
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * The category the skill belongs to.
     *
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
