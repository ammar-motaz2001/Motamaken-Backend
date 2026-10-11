<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['escrow_id', 'user_id', 'share_bp', 'is_lead', 'split_status', 'split_note', 'split_answered_at'])]
class EscrowPayee extends Model
{
    public const SPLIT_PENDING = 'pending';

    public const SPLIT_APPROVED = 'approved';

    public const SPLIT_OBJECTED = 'objected';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['share_bp' => 'integer', 'is_lead' => 'boolean', 'split_answered_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Escrow, $this>
     */
    public function escrow(): BelongsTo
    {
        return $this->belongsTo(Escrow::class);
    }
}
