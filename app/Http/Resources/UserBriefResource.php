<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What other users may see of someone: no email, phone or balance.
 *
 * @mixin User
 */
class UserBriefResource extends JsonResource
{
    /**
     * Returns the public name, username, avatar and online status.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fullName' => $this->full_name,
            'username' => $this->username,
            'avatarUrl' => $this->avatar_url,
            'isOnline' => $this->isOnline(),
        ];
    }
}
