<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name_en', 'name_ar', 'description_en', 'description_ar', 'icon', 'metric', 'target', 'is_active', 'sort_order'])]
class Medal extends Model
{
    /** The numbers a medal can be earned on, computed by App\Services\RewardsService. */
    public const METRICS = ['identity_verified', 'payment_methods', 'completed_jobs', 'five_star_reviews', 'completed_projects', 'promotions', 'referrals'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'target' => 'integer'];
    }

    public function name(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar' ? $this->name_ar : $this->name_en;
    }
}
