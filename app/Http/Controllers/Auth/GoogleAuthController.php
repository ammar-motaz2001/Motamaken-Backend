<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuthMethod;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

#[Group('Auth')]
class GoogleAuthController extends Controller
{
    use IssuesTokens;

    /**
     * Continue with Google.
     *
     * Send the ID token (`credential`) from Google's sign-in button. Google confirms the email, so an existing account
     * with that email signs in and a new email gets an account straight away (`isNew`). Accounts with two-step
     * verification must sign in with their password.
     *
     * @unauthenticated
     *
     * @return array{accessToken: string, user: UserResource, isNew: bool}
     */
    public function login(Request $request): array
    {
        $data = $request->validate(['credential' => ['required', 'string', 'max:4096'], 'referralCode' => ['nullable', 'string', 'max:20']]);
        $clientId = config('services.google.client_id');
        abort_unless($clientId, 404);

        $claims = $this->verify($data['credential'], $clientId);
        $email = Str::lower($claims['email']);

        [$user, $isNew] = DB::transaction(function () use ($claims, $email, $data, $request) {
            $user = User::findByIdentifier(AuthMethod::Email, $email);
            if ($user) {
                if ($user->hasTwoFactor()) {
                    throw new ApiException(__('auth_flow.password_login_required'), 'password_login_required');
                }
                // An unfinished sign-up for this address may not be theirs: drop its password so only they get in.
                if (! $user->isVerified()) {
                    $user->password = null;
                }
                $isNew = ! $user->isVerified();
            } else {
                $user = $this->newUser($claims, $email, $data['referralCode'] ?? null, $request->ip());
                $isNew = true;
            }
            $user->markVerified(AuthMethod::Email);

            return [$user, $isNew];
        });

        abort_if($user->suspended_at, 403, __('auth_flow.suspended'));

        return [...$this->tokenResponse($user), 'isNew' => $isNew];
    }

    /**
     * Checks the ID token with Google: issued for this site, not expired, with a confirmed email.
     *
     * @return array{email: string, given_name?: string, family_name?: string, name?: string}
     */
    private function verify(string $credential, string $clientId): array
    {
        $response = rescue(fn () => Http::timeout(8)->get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $credential]), null, false);
        $claims = $response?->ok() ? $response->json() : null;

        $valid = is_array($claims)
            && ($claims['aud'] ?? null) === $clientId
            && in_array($claims['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)
            && (int) ($claims['exp'] ?? 0) > time()
            && in_array($claims['email_verified'] ?? null, [true, 'true'], true)
            && filter_var($claims['email'] ?? null, FILTER_VALIDATE_EMAIL);

        if (! $valid) {
            throw new ApiException(__('auth_flow.google_failed'), 'google_failed');
        }

        return $claims;
    }

    /**
     * @param  array{given_name?: string, family_name?: string, name?: string}  $claims
     */
    private function newUser(array $claims, string $email, ?string $referralCode, ?string $ip): User
    {
        // Names from Google go through the same "no markup" rule as typed ones.
        $clean = fn (?string $value) => ($value = trim(strip_tags((string) $value))) === '' ? null : mb_substr($value, 0, 50);
        $first = $clean($claims['given_name'] ?? null);
        $last = $clean($claims['family_name'] ?? null);
        $name = $clean($claims['name'] ?? null) ?? trim("{$first} {$last}");
        $username = Str::of($name ?: Str::before($email, '@'))->replaceMatches('/[^\p{L}\p{N}._ ]+/u', ' ')->squish()->limit(30, '')->toString();

        $user = new User([
            'first_name' => $first,
            'last_name' => $last,
            'username' => $username !== '' ? $username : 'User',
            'subscribed' => true,
        ]);
        $user->forceFill(['email' => $email, 'registration_ip' => $ip]);

        if ($referralCode) {
            $user->referred_by = User::where('referral_code', Str::upper($referralCode))->value('id');
        }

        $user->save();

        return $user;
    }
}
