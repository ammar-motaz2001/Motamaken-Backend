<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $kind
 * @property int|null $project_id
 * @property int $days
 * @property int $price_cents
 * @property string $currency
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $package
 * @property int $points_awarded
 * @property-read Project|null $project
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Promotion newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Promotion newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Promotion query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Promotion whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Promotion whereCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Promotion whereDays($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Promotion whereEndsAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Promotion whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Promotion whereKind($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Promotion wherePackage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Promotion wherePointsAwarded($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Promotion wherePriceCents($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Promotion whereProjectId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Promotion whereStartsAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Promotion whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Promotion whereUserId($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['user_id', 'kind', 'package', 'project_id', 'days', 'price_cents', 'currency', 'points_awarded', 'starts_at', 'ends_at'])]
class Promotion extends Model
{
    public const PROFILE = 'profile';

    public const PROJECT = 'project';

    /**
     * Days and price (cents) become integers; the start and end dates.
     */
    protected function casts(): array
    {
        return ['days' => 'integer', 'price_cents' => 'integer', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    /**
     * The project being promoted, null when a profile is promoted.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The user who bought the promotion.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
