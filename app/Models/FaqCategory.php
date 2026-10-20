<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name_en', 'name_ar', 'sort_order', 'is_active'])]
class FaqCategory extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return HasMany<Faq, $this>
     */
    public function faqs(): HasMany
    {
        return $this->hasMany(Faq::class)->orderBy('sort_order')->orderBy('id');
    }
}
