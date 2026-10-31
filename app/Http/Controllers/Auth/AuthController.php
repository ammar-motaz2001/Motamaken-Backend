<?php

namespace App\Http\Controllers\Auth;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\SignupRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Support\Mask;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

#[Group('Authentication', 'Sign up, log in, verification codes, password reset and social login.', weight: 1)]
class AuthController extends Controller
{
    use IssuesTokens;

    public function __construct(private readonly OtpService $otp) {}

    /**
     * Sign up.
     *
     * Creates the account (or refreshes an abandoned, unverified one) and sends a 4-digit signup code
     * by email or WhatsApp. Confirm it with `POST /auth/otp/verify` and `purpose=signup`.
     *
     * @unauthenticated
     */
    public function signup(SignupRequest $request): array
    {
        $method = $request->authMethod();
        $identifier = $request->identifier();

        $user = User::findByIdentifier($method, $identifier) ?? new User;

        abort_if($user->exists && $user->isVerified(), 422, __("auth_flow.already_registered.{$method->value}"));

        // An invite link's code: only counts the first time, and an unknown code is ignored without saying so.
        if ($request->filled('referralCode') && ! $user->referred_by) {
            $user->referred_by = User::where('referral_code', Str::upper($request->input('referralCode')))->value('id');
        }

        $user->registration_ip ??= $request->ip();
        $user->forceFill([$method->column() => $identifier])->fill([
            'first_name' => $request->input('firstName') ?: null,
            'last_name' => $request->input('lastName') ?: null,
            'username' => $request->input('username'),
            'password' => $request->input('password'),
            'subscribed' => $request->boolean('subscribe'),
            // The language of the page they signed up from: their emails follow it.
            'locale' => $user->locale ?? app()->getLocale(),
            // A new account starts in the page's usual currency: riyals on the Arabic site.
            'currency' => $user->exists ? $user->currency : (app()->getLocale() === 'ar' ? 'SAR' : 'USD'),
        ])->save();

        $this->otp->send(OtpPurpose::Signup, $method, $identifier);

        return ['target' => $identifier];
    }

    /**
     * Log in.
     *
     * Checks the password. Returns an access token directly, or `otpRequired: true` after sending a login code,
     * which is always the case for unverified identifiers and for methods in `LOGIN_OTP_METHODS` (default: email).
     * Finish with `POST /auth/otp/verify` and `purpose=login`.
     *
     * @unauthenticated
     */
    public function login(LoginRequest $request): array
    {
        $method = $request->authMethod();
        $identifier = $request->identifier();
        $user = User::findByIdentifier($method, $identifier);

        // One message whether the account is missing or the password is wrong, and a hash check either way so the
        // response time does not tell them apart either: login cannot be used to find out who is registered.
        $hash = $user?->password ?: '$2y$12$'.str_repeat('a', 53);
        if (! Hash::check($request->input('password'), $hash) || ! $user?->password) {
            abort(422, __("auth_flow.invalid_credentials.{$method->value}"));
        }

        // Two-factor: the code goes to the second factor, whatever the login method.
        if ($user->hasTwoFactor()) {
            $this->otp->send(OtpPurpose::Login, $user->twoFactorMethod(), $user->two_factor_target);

            return ['otpRequired' => true, 'twoFactor' => [
                'method' => $user->two_factor_method,
                'target' => Mask::identifier($user->twoFactorMethod(), $user->two_factor_target),
            ]];
        }

        $identifierVerified = $user->{$method->verifiedColumn()} !== null;

        if (! $identifierVerified || in_array($method->value, config('auth_flow.login_otp_methods'), true)) {
            $this->otp->send(OtpPurpose::Login, $method, $identifier);

            return ['otpRequired' => true];
        }

        return ['otpRequired' => false, ...$this->tokenResponse($user)];
    }

    /**
     * Current user.
     */
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /**
     * Log out.
     *
     * Revokes the access token used for this request.
     */
    public function logout(Request $request): array
    {
        $request->user()->currentAccessToken()->delete();

        return ['success' => true];
    }
}
