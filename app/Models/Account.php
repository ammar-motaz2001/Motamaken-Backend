<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['type', 'owner_key', 'user_id', 'currency', 'balance_cents'])]
class Account extends Model
{
    /** A user's spendable money. */
    public const WALLET = 'wallet';

    /** Money held for escrows until it is released or refunded. */
    public const ESCROW = 'escrow';

    /** The platform's earnings: fees and subscriptions. */
    public const REVENUE = 'revenue';

    /** The outside world: money that came in by card/bank or left by payout. Goes negative as money comes in. */
    public const GATEWAY = 'gateway';

    /** Money reserved for withdrawals not paid out yet. */
    public const WITHDRAWALS = 'withdrawals';

    /** Budgets of promotion campaigns, paid out to promoters as visits are counted. */
    public const CAMPAIGNS = 'campaigns';

    /** What the platform spends on prizes (prize draw). An expense: goes negative as prizes are paid. */
    public const MARKETING = 'marketing';

    /** @var array<string, mixed> */
    protected $attributes = ['balance_cents' => 0];

    protected function casts(): array
    {
        return ['balance_cents' => 'integer'];
    }

    /**
     * Only the gateway account may go below zero: it mirrors the money that entered the platform.
     */
    public function allowsNegative(): bool
    {
        return in_array($this->type, [self::GATEWAY, self::MARKETING], true);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
