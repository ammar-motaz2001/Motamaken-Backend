<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $title_en
 * @property string $title_ar
 * @property int $ticket_cost
 * @property int|null $max_tickets_per_user
 * @property Carbon $starts_at
 * @property Carbon $draw_at
 * @property string $status
 * @property array<array-key, mixed> $prizes
 * @property Carbon|null $drawn_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, DrawEntry> $entries
 * @property-read int|null $entries_count
 * @property-read Collection<int, DrawWinner> $winners
 * @property-read int|null $winners_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Draw newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Draw newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Draw query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Draw whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Draw whereDrawAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Draw whereDrawnAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Draw whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Draw whereMaxTicketsPerUser($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Draw wherePrizes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Draw whereStartsAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Draw whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Draw whereTicketCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Draw whereTitleAr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Draw whereTitleEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Draw whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['title_en', 'title_ar', 'ticket_cost', 'max_tickets_per_user', 'starts_at', 'draw_at', 'status', 'prizes', 'drawn_at'])]
class Draw extends Model
{
    public const OPEN = 'open';

    public const DRAWN = 'drawn';

    public const CANCELLED = 'cancelled';

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'open'];

    /**
     * Ticket numbers become integers, the schedule dates, and prizes an array.
     */
    protected function casts(): array
    {
        return ['ticket_cost' => 'integer', 'max_tickets_per_user' => 'integer', 'starts_at' => 'datetime', 'draw_at' => 'datetime', 'drawn_at' => 'datetime', 'prizes' => 'array'];
    }

    /**
     * The tickets users bought, one row per user.
     *
     * @return HasMany<DrawEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(DrawEntry::class);
    }

    /**
     * The winners, in prize rank order.
     *
     * @return HasMany<DrawWinner, $this>
     */
    public function winners(): HasMany
    {
        return $this->hasMany(DrawWinner::class)->orderBy('rank');
    }

    /**
     * The title in the request language.
     */
    public function title(): string
    {
        return app()->getLocale() === 'ar' ? $this->title_ar : $this->title_en;
    }

    /**
     * Whether users can buy tickets now: open, started and not drawn yet.
     */
    public function isOpenForEntries(): bool
    {
        return $this->status === self::OPEN && $this->starts_at->isPast() && $this->draw_at->isFuture();
    }
}
