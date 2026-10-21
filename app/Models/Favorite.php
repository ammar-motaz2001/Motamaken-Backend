<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property int $target_id
 * @property Carbon $created_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Favorite newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Favorite newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Favorite query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Favorite whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Favorite whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Favorite whereTargetId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Favorite whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Favorite whereUserId($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['user_id', 'type', 'target_id'])]
class Favorite extends Model
{
    public const UPDATED_AT = null;

    public const PROJECT = 'project';

    public const PROFILE = 'profile';

    public const TYPES = [self::PROJECT, self::PROFILE];
}
