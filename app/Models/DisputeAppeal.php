<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['dispute_id', 'user_id', 'reason', 'status', 'decision_note', 'adjustment_cents', 'adjustment_to', 'decided_by', 'decided_at'])]
class DisputeAppeal extends Model
{
    public const PENDING = 'pending';

    public const UPHELD = 'upheld';

    public const OVERTURNED = 'overturned';

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::PENDING];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'adjustment_cents' => 'integer'];
    }

    /**
     * @return BelongsTo<Dispute, $this>
     */
    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
