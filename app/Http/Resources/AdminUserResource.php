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
     * Returns the user with contact details, admin role, verification, points, suspension and stats.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fullName' => $this->full_name,
            'firstName' => $this->first_name,
            'lastName' => $this->last_name,
            'username' => $this->username,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatarUrl' => $this->avatar_url,
            'isAdmin' => (bool) $this->is_admin,
            'isVerified' => $this->isVerified(),
            /** `none`, `pending`, `approved`, `rejected` or `needs_update`. */
            'kycStatus' => $this->kyc_status,
            /** `client`, `freelancer` or `influencer`. */
            'accountType' => $this->relationLoaded('profile') ? ($this->profile?->type ?? 'client') : null,
            /** The staff role, for admins with a limited role. */
            'adminRole' => $this->is_admin && $this->relationLoaded('adminRole') && $this->adminRole
                ? ['id' => $this->adminRole->id, 'slug' => $this->adminRole->slug, 'nameEn' => $this->adminRole->name_en, 'nameAr' => $this->adminRole->name_ar]
                : null,
            'twoFactor' => $this->hasTwoFactor(),
            'points' => $this->points,
            'suspendedAt' => $this->suspended_at,
            'suspensionReason' => $this->suspension_reason,
            /** End of a timed suspension; null with `suspendedAt` set means banned until lifted. */
            'suspendedUntil' => $this->suspended_until,
            'lastSeenAt' => $this->last_seen_at,
            'createdAt' => $this->created_at,
            /** Only in the detail view. */
            'stats' => $this->when(isset($this->stats), fn () => $this->stats),
        ];
    }
}
