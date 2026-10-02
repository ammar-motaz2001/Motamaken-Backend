<?php

namespace App\Http\Resources;

use App\Models\LedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of the wallet history.
 *
 * @mixin LedgerEntry
 */
class LedgerEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $meta = $this->transaction->meta ?? [];

        return [
            'id' => $this->id,
            /** E.g. `deposit`, `withdrawal_request`, `escrow_fund`, `escrow_release`, `dispute_settlement`, `subscription`. */
            'type' => $this->transaction->type,
            /** Ready to show, in the language of the `Accept-Language` header. */
            'label' => __("payments.types.{$this->transaction->type}", [
                'project' => $meta['project'] ?? '',
                'milestone' => $meta['milestone'] ?? '',
            ]),
            /** Positive money in, negative money out. */
            'amount' => $this->amount_cents / 100,
            'currency' => $this->account->currency,
            /** The wallet balance after this line. */
            'balanceAfter' => $this->balance_after_cents / 100,
            /** What it was for, to link to: `deposit`, `withdrawal`, `milestone`... */
            'reference' => $this->transaction->reference_id ? [
                'type' => class_basename($this->transaction->reference_type),
                'id' => $this->transaction->reference_id,
            ] : null,
            'createdAt' => $this->created_at,
        ];
    }
}
