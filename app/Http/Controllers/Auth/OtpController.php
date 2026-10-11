<?php

namespace App\Http\Controllers\Auth;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResendOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Models\User;
use App\Services\Auth\OtpService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

#[Group('Authentication', 'Sign up, log in, verification codes, password reset and social login.', weight: 1)]
class OtpController extends Controller
{
    use IssuesTokens;

    public function __construct(private readonly OtpService $otp) {}

    /**
     * Verify a code.
     *
     * Response depends on `purpose`: `signup` → `{verified}`, `login` → `{accessToken, user}`,
     * `reset` → `{token}` for `POST /auth/password/reset`. Codes are valid for 10 minutes;
     * 5 wrong attempts invalidate the code (429).
     *
     * @unauthenticated
     */
    public function verify(VerifyOtpRequest $request): array
    {
        $purpose = $request->purpose();
        $method = $request->authMethod();
        $identifier = $request->identifier();

        $user = User::findByIdentifier($method, $identifier);
        $twoFactor = $purpose === OtpPurpose::Login && $user?->hasTwoFactor();

        // With two-factor on, the login code was sent to the second factor, not to the login identifier.
        $twoFactor
            ? $this->otp->verify($purpose, $user->twoFactorMethod(), $user->two_factor_target, $request->input('code'))
            : $this->otp->verify($purpose, $method, $identifier, $request->input('code'));

        abort_if(! $user, 422, __('auth_flow.otp_invalid'));

        // Receiving the code proves ownership of the identifier it was sent to.
        if (! $twoFactor) {
            $user->markVerified($method);
        }

        return match ($purpose) {
            OtpPurpose::Signup => ['verified' => true],
            OtpPurpose::Login => $this->tokenResponse($user),
            OtpPurpose::Reset => ['token' => $this->issueResetToken($user)],
        };
    }

    /**
     * Resend a code.
     *
     * Only works for a code previously issued by signup, login or forgot-password, and at most once every 60 seconds (429).
     * Always answers `{sent: true}` so it can't be used to discover accounts.
     *
     * @unauthenticated
     */
    public function resend(ResendOtpRequest $request): array
    {
        $user = $request->purpose() === OtpPurpose::Login ? User::findByIdentifier($request->authMethod(), $request->identifier()) : null;

        $user?->hasTwoFactor()
            ? $this->otp->resend(OtpPurpose::Login, $user->twoFactorMethod(), $user->two_factor_target)
            : $this->otp->resend($request->purpose(), $request->authMethod(), $request->identifier());

        // Same answer whether or not a code was pending, so this can't be used to probe accounts.
        return ['sent' => true];
    }

    private function issueResetToken(User $user): string
    {
        $token = Str::random(64);

        Cache::put(PasswordController::resetTokenKey($token), $user->id, now()->addMinutes(config('auth_flow.reset_token_ttl')));

        return $token;
    }
}
