<?php

namespace App\Http\Resources;

use App\Models\Withdrawal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Withdrawal
 */
class WithdrawalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $destination = $this->destination;
        $account = $destination['iban'] ?? $destination['email'] ?? '';

        return [
            'id' => $this->id,
            'amount' => $this->amount_cents / 100,
            /** Charged on top of the amount. */
            'fee' => $this->fee_cents / 100,
            'currency' => $this->currency,
            'method' => $this->method,
            /** Only the end of the account is shown back, e.g. `…4567`. */
            'destination' => '…'.substr(str_replace(' ', '', $account), -4),
            /** `pending` (waiting for an admin), `paid`, `rejected` or `cancelled`. */
            'status' => $this->status,
            'payoutReference' => $this->payout_reference,
            'rejectReason' => $this->reject_reason,
            /** Admin lists include who asked. */
            'user' => new UserBriefResource($this->whenLoaded('user')),
            'createdAt' => $this->created_at,
            'reviewedAt' => $this->reviewed_at,
        ];
    }
}
