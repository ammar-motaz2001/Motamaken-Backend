<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $name_en
 * @property string $name_ar
 * @property string|null $description_en
 * @property string|null $description_ar
 * @property string|null $icon
 * @property string|null $metric
 * @property int $target
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Medal newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Medal newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Medal query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Medal whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Medal whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Medal whereDescriptionAr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Medal whereDescriptionEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Medal whereIcon($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Medal whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Medal whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Medal whereMetric($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Medal whereNameAr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Medal whereNameEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Medal whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Medal whereTarget($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Medal whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['code', 'name_en', 'name_ar', 'description_en', 'description_ar', 'icon', 'metric', 'target', 'is_active', 'sort_order'])]
class Medal extends Model
{
    /** The numbers a medal can be earned on, computed by App\Services\RewardsService. */
    public const METRICS = ['identity_verified', 'payment_methods', 'completed_jobs', 'five_star_reviews', 'completed_projects', 'promotions', 'referrals'];

    /**
     * is_active becomes a boolean, the target an integer.
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'target' => 'integer'];
    }

    /**
     * The medal name in the given language, or the request language when none is given.
     */
    public function name(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar' ? $this->name_ar : $this->name_en;
    }
}
