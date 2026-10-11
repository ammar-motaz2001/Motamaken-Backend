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
            'accessToken' => $this->newToken($user),
            'user' => new UserResource($user),
        ];
    }

    /**
     * A session token, remembering where it was opened for the "Login devices" list.
     */
    protected function newToken(User $user): string
    {
        $token = $user->createToken('web');
        $token->accessToken->forceFill([
            'ip_address' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 255),
        ])->save();

        return $token->plainTextToken;
    }
}
