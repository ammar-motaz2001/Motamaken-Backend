<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * Returns the logged-in user's own account: names, contacts, points, preferences and admin permissions.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'firstName' => $this->first_name,
            'lastName' => $this->last_name,
            'fullName' => $this->full_name,
            'username' => $this->username,
            'avatarUrl' => $this->avatar_url,
            'email' => $this->email,
            'emailVerifiedAt' => $this->email_verified_at,
            'phone' => $this->phone,
            'phoneVerifiedAt' => $this->phone_verified_at,
            'points' => $this->points,
            'subscribed' => $this->subscribed,
            /** Chosen header language (`en`, `ar`, `fr`), or null to follow the browser. */
            'locale' => $this->locale,
            'currency' => $this->currency,
            /** `light` or `dark`. */
            'theme' => $this->theme,
            /** Opens the admin panel on the website. */
            'isAdmin' => (bool) $this->is_admin,
            /** What the admin may use in the panel (config/admin.php permissions); empty for everyone else. */
            'adminPermissions' => $this->adminPermissions(),
            'createdAt' => $this->created_at,
        ];
    }
}
