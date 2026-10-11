<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title_en', 'title_ar', 'ticket_cost', 'max_tickets_per_user', 'starts_at', 'draw_at', 'status', 'prizes', 'drawn_at'])]
class Draw extends Model
{
    public const OPEN = 'open';

    public const DRAWN = 'drawn';

    public const CANCELLED = 'cancelled';

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return ['ticket_cost' => 'integer', 'max_tickets_per_user' => 'integer', 'starts_at' => 'datetime', 'draw_at' => 'datetime', 'drawn_at' => 'datetime', 'prizes' => 'array'];
    }

    /**
     * @return HasMany<DrawEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(DrawEntry::class);
    }

    /**
     * @return HasMany<DrawWinner, $this>
     */
    public function winners(): HasMany
    {
        return $this->hasMany(DrawWinner::class)->orderBy('rank');
    }

    public function title(): string
    {
        return app()->getLocale() === 'ar' ? $this->title_ar : $this->title_en;
    }

    public function isOpenForEntries(): bool
    {
        return $this->status === self::OPEN && $this->starts_at->isPast() && $this->draw_at->isFuture();
    }
}
