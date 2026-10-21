<?php

namespace App\Http\Resources;

use App\Models\Deposit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Deposit
 */
class DepositResource extends JsonResource
{
    /**
     * Returns the top-up's amount, method, status, checkout link and payment time.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount_cents / 100,
            'currency' => $this->currency,
            'method' => $this->method,
            /** `pending` (waiting for the payment), `succeeded` (in the wallet) or `failed`. */
            'status' => $this->status,
            /** Send the customer here to pay. */
            'checkoutUrl' => $this->status === Deposit::PENDING ? $this->checkout_url : null,
            /** Why a `failed` payment failed, e.g. the bank's answer. */
            'failureReason' => $this->failure_reason,
            'paidAt' => $this->paid_at,
            'createdAt' => $this->created_at,
        ];
    }
}
