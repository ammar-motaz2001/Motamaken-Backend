<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'type', 'brand', 'last4', 'exp_month', 'exp_year', 'holder_name', 'email', 'gateway_token', 'is_default'])]
class PaymentMethod extends Model
{
    public const CARD = 'card';

    public const PAYPAL = 'paypal';

    /** @var list<string> */
    protected $hidden = ['gateway_token'];

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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
