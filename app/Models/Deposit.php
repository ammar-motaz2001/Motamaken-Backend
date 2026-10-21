<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $amount_cents
 * @property string $currency
 * @property string $method
 * @property string $status
 * @property string $provider
 * @property string $provider_ref
 * @property string|null $checkout_url
 * @property Carbon|null $paid_at
 * @property string|null $failure_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Deposit newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Deposit newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Deposit query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Deposit whereAmountCents($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Deposit whereCheckoutUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Deposit whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Deposit whereCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Deposit whereFailureReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Deposit whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Deposit whereMethod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Deposit wherePaidAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Deposit whereProvider($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Deposit whereProviderRef($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Deposit whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Deposit whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Deposit whereUserId($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['user_id', 'amount_cents', 'currency', 'method', 'status', 'provider', 'provider_ref', 'checkout_url', 'paid_at', 'failure_reason'])]
class Deposit extends Model
{
    use HasFactory;

    public const PENDING = 'pending';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending'];

    /**
     * The amount is whole cents; paid_at becomes a date.
     */
    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'paid_at' => 'datetime'];
    }

    /**
     * The user whose wallet the deposit tops up.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
