<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuthMethod;
use App\Enums\OtpPurpose;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\QuickStartRequest;
use App\Http\Requests\Auth\QuickVerifyRequest;
use App\Models\User;
use App\Services\Auth\OtpService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Use first, sign up when it matters": a guest fills a form as a guest and, at the final button (publish, send, pay),
 * confirms an email or phone with a one-time code. Existing accounts are signed in, new ones are created on the spot,
 * and the website then finishes the action it was holding.
 */
#[Group('Authentication', 'Sign up, log in, verification codes, password reset and quick access.', weight: 1)]
class QuickAuthController extends Controller
{
    use IssuesTokens;

    public function __construct(private readonly OtpService $otp) {}

    /**
     * Quick access: send a code.
     *
     * Sends a code to the email or phone, whether or not it already has an account (the answer is the same, so this
     * cannot be used to probe accounts). Accounts with two-step verification must log in with their password
     * (`password_login_required`). Resend with `POST /auth/otp/resend` and `purpose=quick`.
     *
     * @unauthenticated
     *
     * @return array{sent: bool, target: string}
     */
    public function start(QuickStartRequest $request): array
    {
        $method = $request->authMethod();
        $identifier = $request->identifier();

        if (User::findByIdentifier($method, $identifier)?->hasTwoFactor()) {
            throw new ApiException(__('auth_flow.password_login_required'), 'password_login_required');
        }

        $this->otp->send(OtpPurpose::Quick, $method, $identifier);

        return ['sent' => true, 'target' => $identifier];
    }

    /**
     * Quick access: confirm the code.
     *
     * Signs the person in, creating their account first when the email or phone is new (`isNew: true`; it has no
     * password until they set one with "Forgot password"). The code proves the address is theirs, so it is marked verified.
     *
     * @unauthenticated
     *
     * @return array{accessToken: string, user: array<string, mixed>, isNew: bool}
     */
    public function verify(QuickVerifyRequest $request): array
    {
        $method = $request->authMethod();
        $identifier = $request->identifier();

        $this->otp->verify(OtpPurpose::Quick, $method, $identifier, $request->input('code'));

        [$user, $isNew] = DB::transaction(function () use ($request, $method, $identifier) {
            $user = User::findByIdentifier($method, $identifier);
            if ($user) {
                // A two-step account must not be opened with the first factor alone.
                if ($user->hasTwoFactor()) {
                    throw new ApiException(__('auth_flow.password_login_required'), 'password_login_required');
                }
                // An unfinished sign-up for this address may not be theirs: drop its password so only they get in.
                if (! $user->isVerified()) {
                    $user->password = null;
                }
                $isNew = ! $user->isVerified();
            } else {
                $user = $this->newUser($request, $method, $identifier);
                $isNew = true;
            }
            $user->markVerified($method);

            return [$user, $isNew];
        });

        return [...$this->tokenResponse($user), 'isNew' => $isNew];
    }

    /**
     * Creates the account for a first quick sign-in, taking a name from the email when none was given.
     */
    private function newUser(QuickVerifyRequest $request, AuthMethod $method, string $identifier): User
    {
        $name = trim((string) $request->input('name'));
        if ($name === '' && $method === AuthMethod::Email) {
            // "sara.ahmed@x.com" -> "Sara Ahmed"
            $name = Str::of(Str::before($identifier, '@'))->replaceMatches('/[^\pL]+/u', ' ')->squish()->title()->toString();
        }
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, null);

        $user = new User([
            'first_name' => $first ?: null,
            'last_name' => $last,
            'username' => $name !== '' ? $name : 'User',
            'subscribed' => true,
        ]);
        $user->forceFill([$method->column() => $identifier, 'registration_ip' => $request->ip()]);

        if ($request->filled('referralCode')) {
            $user->referred_by = User::where('referral_code', Str::upper($request->input('referralCode')))->value('id');
        }

        $user->save();

        return $user;
    }
}
