<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['type', 'slug', 'name_en', 'name_ar', 'icon', 'sort_order', 'parent_id', 'is_active'])]
class Category extends Model
{
    use HasFactory, HasLocalizedName;

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
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }
}
