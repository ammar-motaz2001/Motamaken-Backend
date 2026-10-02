<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user as admins see them: contact details, verification and suspension.
 *
 * @mixin User
 */
class AdminUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fullName' => $this->full_name,
            'username' => $this->username,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatarUrl' => $this->avatar_url,
            'isAdmin' => (bool) $this->is_admin,
            'isVerified' => $this->isVerified(),
            /** `none`, `pending`, `approved` or `rejected`. */
            'kycStatus' => $this->kyc_status,
            'points' => $this->points,
            'suspendedAt' => $this->suspended_at,
            'suspensionReason' => $this->suspension_reason,
            'lastSeenAt' => $this->last_seen_at,
            'createdAt' => $this->created_at,
            /** Only in the detail view. */
            'stats' => $this->when(isset($this->stats), fn () => $this->stats),
        ];
    }
}
