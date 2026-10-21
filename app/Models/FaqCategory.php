<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name_en
 * @property string $name_ar
 * @property int $sort_order
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Faq> $faqs
 * @property-read int|null $faqs_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FaqCategory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FaqCategory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FaqCategory query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FaqCategory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FaqCategory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FaqCategory whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FaqCategory whereNameAr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FaqCategory whereNameEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FaqCategory whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FaqCategory whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['name_en', 'name_ar', 'sort_order', 'is_active'])]
class FaqCategory extends Model
{
    /**
     * is_active becomes a boolean.
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * The questions in this section, in display order.
     *
     * @return HasMany<Faq, $this>
     */
    public function faqs(): HasMany
    {
        return $this->hasMany(Faq::class)->orderBy('sort_order')->orderBy('id');
    }
}
