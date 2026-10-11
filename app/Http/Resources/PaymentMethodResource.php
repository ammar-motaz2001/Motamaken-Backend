<?php

namespace App\Http\Resources;

use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PaymentMethod
 */
class PaymentMethodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $email = (string) $this->email;

        return [
            'id' => $this->id,
            /** `card` or `paypal`. */
            'type' => $this->type,
            /** Cards: `visa`, `mastercard`, `amex`, `mada` or `other`. */
            'brand' => $this->brand,
            'last4' => $this->last4,
            'expMonth' => $this->exp_month,
            'expYear' => $this->exp_year,
            'holderName' => $this->holder_name,
            /** PayPal: the account email, masked, e.g. `am•••@gmail.com`. */
            'email' => $email ? substr($email, 0, 2).'•••'.substr($email, (int) strpos($email, '@')) : null,
            'isDefault' => $this->is_default,
            'createdAt' => $this->created_at,
        ];
    }
}
