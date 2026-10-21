<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $amount
 * @property string $reason
 * @property string|null $key
 * @property array<array-key, mixed>|null $meta
 * @property Carbon $created_at
 * @property int|null $created_by
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PointTransaction newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PointTransaction newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PointTransaction query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PointTransaction whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PointTransaction whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PointTransaction whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PointTransaction whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PointTransaction whereKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PointTransaction whereMeta($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PointTransaction whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PointTransaction whereUserId($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['user_id', 'amount', 'reason', 'key', 'meta', 'created_by'])]
class PointTransaction extends Model
{
    public const UPDATED_AT = null;

    /**
     * The points amount becomes an integer; meta an array.
     */
    protected function casts(): array
    {
        return ['amount' => 'integer', 'meta' => 'array'];
    }

    /**
     * The user whose points changed.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
