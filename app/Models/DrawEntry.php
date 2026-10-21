<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $draw_id
 * @property int $user_id
 * @property int $tickets
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DrawEntry newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DrawEntry newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DrawEntry query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DrawEntry whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DrawEntry whereDrawId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DrawEntry whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DrawEntry whereTickets($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DrawEntry whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DrawEntry whereUserId($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['draw_id', 'user_id', 'tickets'])]
class DrawEntry extends Model
{
    /**
     * The number of tickets bought becomes an integer.
     */
    protected function casts(): array
    {
        return ['tickets' => 'integer'];
    }
}
