<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'amount_cents', 'fee_cents', 'currency', 'method', 'destination', 'status', 'payout_reference', 'reject_reason', 'reviewed_by', 'reviewed_at'])]
class Withdrawal extends Model
{
    use HasFactory;

    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending', 'fee_cents' => 0];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'fee_cents' => 'integer',
            'destination' => 'encrypted:array',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
