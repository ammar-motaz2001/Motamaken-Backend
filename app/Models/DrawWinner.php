<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $draw_id
 * @property int $user_id
 * @property int $rank
 * @property array<array-key, mixed> $prize
 * @property string|null $claim_method
 * @property string|null $claim_details
 * @property Carbon|null $claimed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Draw $draw
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DrawWinner newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DrawWinner newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DrawWinner query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DrawWinner whereClaimDetails($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DrawWinner whereClaimMethod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DrawWinner whereClaimedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DrawWinner whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DrawWinner whereDrawId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DrawWinner whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DrawWinner wherePrize($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DrawWinner whereRank($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DrawWinner whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DrawWinner whereUserId($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['draw_id', 'user_id', 'rank', 'prize', 'claim_method', 'claim_details', 'claimed_at'])]
class DrawWinner extends Model
{
    /**
     * The prize comes back as an array, the claim details are stored encrypted, and claimed_at becomes a date.
     */
    protected function casts(): array
    {
        return ['rank' => 'integer', 'prize' => 'array', 'claim_details' => 'encrypted', 'claimed_at' => 'datetime'];
    }

    /**
     * The user who won.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The draw that was won.
     *
     * @return BelongsTo<Draw, $this>
     */
    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class);
    }
}
