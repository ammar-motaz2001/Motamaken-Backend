<?php

namespace App\Http\Controllers\Auth;

use App\Http\Resources\UserResource;
use App\Models\User;

trait IssuesTokens
{
    /**
     * @return array{accessToken: string, user: UserResource}
     */
    protected function tokenResponse(User $user): array
    {
        return [
            'accessToken' => $user->createToken('web')->plainTextToken,
            'user' => new UserResource($user),
        ];
    }
}
