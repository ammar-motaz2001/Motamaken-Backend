<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $escrow_id
 * @property int $client_id
 * @property int $amount_cents
 * @property string $currency
 * @property string|null $note
 * @property Carbon $created_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tip newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tip newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tip query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tip whereAmountCents($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tip whereClientId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tip whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tip whereCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tip whereEscrowId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tip whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tip whereNote($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['escrow_id', 'client_id', 'amount_cents', 'currency', 'note'])]
class Tip extends Model
{
    public const UPDATED_AT = null;

    /**
     * The amount is whole cents.
     */
    protected function casts(): array
    {
        return ['amount_cents' => 'integer'];
    }
}
