<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['profile_id', 'title', 'description', 'image_path', 'link', 'position'])]
class PortfolioItem extends Model
{
    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn () => Storage::disk('public')->url($this->image_path));
    }

    /**
     * @return BelongsTo<Profile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
