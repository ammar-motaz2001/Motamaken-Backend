<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * For tables with name_en / name_ar columns: `$model->name` follows the request language (Accept-Language).
 */
trait HasLocalizedName
{
    /**
     * The name in the request language, read as `name`.
     *
     * @return Attribute<string, never>
     */
    protected function name(): Attribute
    {
        return Attribute::get(fn () => app()->getLocale() === 'ar' ? $this->name_ar : $this->name_en);
    }
}
