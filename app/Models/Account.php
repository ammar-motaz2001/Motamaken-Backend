<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $type
 * @property string $owner_key
 * @property int|null $user_id
 * @property string $currency
 * @property int $balance_cents
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account whereBalanceCents($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account whereCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account whereOwnerKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account whereUserId($value)
 *
 * @mixin \Eloquent
 */
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

    /**
     * Balances are whole cents as integers.
     */
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
     * The user who owns this wallet, null for the platform's own accounts.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
