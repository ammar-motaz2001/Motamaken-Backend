<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $code
 * @property Carbon $awarded_at
 * @property int|null $awarded_by
 * @property string|null $reason
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMedal newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMedal newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMedal query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMedal whereAwardedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMedal whereAwardedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMedal whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMedal whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMedal whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMedal whereUserId($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['user_id', 'code', 'awarded_at', 'awarded_by', 'reason'])]
class UserMedal extends Model
{
    public $timestamps = false;

    /**
     * awarded_at becomes a date.
     */
    protected function casts(): array
    {
        return ['awarded_at' => 'datetime'];
    }
}
