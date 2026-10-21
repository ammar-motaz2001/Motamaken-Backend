<?php

namespace App\Http\Resources;

use App\Models\KycSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An identity check as the user sees it: the document number is masked.
 *
 * @mixin KycSubmission
 */
class KycResource extends JsonResource
{
    /**
     * Returns the check's status, document details with the number masked, and any rejection reason.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** `pending` (being reviewed), `approved` or `rejected`. */
            'status' => $this->status,
            'documentType' => $this->document_type,
            /** Only the last 4 characters, e.g. `••••5678`. */
            'documentNumber' => '••••'.substr($this->document_number, -4),
            'fullName' => $this->full_name,
            'dateOfBirth' => $this->date_of_birth->toDateString(),
            /** Why it was rejected, to show the user before they try again. */
            'rejectionReason' => $this->rejection_reason,
            'submittedAt' => $this->created_at,
            'reviewedAt' => $this->reviewed_at,
        ];
    }
}
