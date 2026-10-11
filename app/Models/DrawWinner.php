<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['draw_id', 'user_id', 'rank', 'prize', 'claim_method', 'claim_details', 'claimed_at'])]
class DrawWinner extends Model
{
    protected function casts(): array
    {
        return ['rank' => 'integer', 'prize' => 'array', 'claim_details' => 'encrypted', 'claimed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Draw, $this>
     */
    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class);
    }
}
