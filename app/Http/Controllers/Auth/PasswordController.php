<?php

namespace App\Http\Controllers\Auth;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Services\Auth\OtpService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Support\Facades\Cache;

#[Group('Authentication', 'Sign up, log in, verification codes, password reset and social login.', weight: 1)]
class PasswordController extends Controller
{
    /**
     * Forgot password.
     *
     * Sends a reset code if the account exists, and always answers `{sent: true}`. No token is returned here:
     * verify the code with `POST /auth/otp/verify` and `purpose=reset` to get one.
     *
     * @unauthenticated
     */
    public function forgot(ForgotPasswordRequest $request, OtpService $otp): array
    {
        $method = $request->authMethod();
        $identifier = $request->identifier();

        if (User::findByIdentifier($method, $identifier)) {
            $otp->send(OtpPurpose::Reset, $method, $identifier);
        }

        return ['sent' => true];
    }

    /**
     * Reset password.
     *
     * Sets a new password using the token from the reset code verification (single use, valid 15 minutes),
     * and logs the user out on every device.
     *
     * @unauthenticated
     */
    public function reset(ResetPasswordRequest $request): array
    {
        $user = User::find(Cache::pull(self::resetTokenKey($request->input('token'))));

        abort_if(! $user, 422, __('auth_flow.reset_token_invalid'));

        $user->update(['password' => $request->input('password')]);
        $user->tokens()->delete();

        return ['success' => true];
    }

    public static function resetTokenKey(string $token): string
    {
        return 'password-reset:'.hash('sha256', $token);
    }
}
