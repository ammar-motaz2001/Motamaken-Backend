<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property string|null $brand
 * @property string|null $last4
 * @property int|null $exp_month
 * @property int|null $exp_year
 * @property string|null $holder_name
 * @property string|null $email
 * @property string $gateway_token
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentMethod newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentMethod newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentMethod query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentMethod whereBrand($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentMethod whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentMethod whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentMethod whereExpMonth($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentMethod whereExpYear($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentMethod whereGatewayToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentMethod whereHolderName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentMethod whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentMethod whereIsDefault($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentMethod whereLast4($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentMethod whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentMethod whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentMethod whereUserId($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['user_id', 'type', 'brand', 'last4', 'exp_month', 'exp_year', 'holder_name', 'email', 'gateway_token', 'is_default'])]
class PaymentMethod extends Model
{
    public const CARD = 'card';

    public const PAYPAL = 'paypal';

    /** @var list<string> */
    protected $hidden = ['gateway_token'];

    /**
     * Expiry month and year become integers; the email and gateway token are stored encrypted.
     */
    protected function casts(): array
    {
        return [
            'exp_month' => 'integer',
            'exp_year' => 'integer',
            'email' => 'encrypted',
            'gateway_token' => 'encrypted',
            'is_default' => 'boolean',
        ];
    }

    /**
     * The user who saved the payment method.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
