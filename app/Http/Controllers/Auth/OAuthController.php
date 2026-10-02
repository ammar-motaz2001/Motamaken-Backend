<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuthMethod;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialUser;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Browser flow: GET /api/auth/oauth/{provider} → provider → callback here → redirect to the frontend
 * with a one-time ?code= that the frontend exchanges for an access token via POST /api/auth/oauth/exchange.
 * The token itself never appears in a URL.
 */
#[Group('Authentication', 'Sign up, log in, verification codes, password reset and social login.', weight: 1)]
class OAuthController extends Controller
{
    use IssuesTokens;

    /**
     * Social login (browser redirect).
     *
     * Open this URL in the browser, not with fetch. After the provider, the browser lands on
     * `FRONTEND_URL/oauth/callback?code=...` (or `?error=provider_unavailable|oauth_failed`).
     *
     * @unauthenticated
     */
    public function redirect(string $provider): RedirectResponse
    {
        if (! config("services.{$provider}.client_id")) {
            return $this->toFrontend(['error' => 'provider_unavailable']);
        }

        return $this->driver($provider)->redirect();
    }

    #[ExcludeRouteFromDocs]
    public function callback(string $provider): RedirectResponse
    {
        try {
            $socialUser = $this->driver($provider)->user();
        } catch (Throwable $e) {
            Log::warning("OAuth callback failed for {$provider}: {$e->getMessage()}");

            return $this->toFrontend(['error' => 'oauth_failed']);
        }

        $user = DB::transaction(fn () => $this->resolveUser($provider, $socialUser));

        $code = Str::random(64);
        Cache::put(self::exchangeKey($code), $user->id, now()->addMinute());

        return $this->toFrontend(['code' => $code]);
    }

    /**
     * Exchange OAuth code.
     *
     * Trades the one-time `code` from the OAuth redirect (valid 1 minute) for an access token.
     *
     * @unauthenticated
     */
    public function exchange(Request $request): array
    {
        $request->validate(['code' => ['required', 'string']]);

        $user = User::find(Cache::pull(self::exchangeKey($request->input('code'))));

        abort_if(! $user, 422, __('auth_flow.oauth_code_invalid'));

        return $this->tokenResponse($user);
    }

    private function resolveUser(string $provider, SocialUser $socialUser): User
    {
        $account = SocialAccount::where('provider', $provider)->where('provider_id', $socialUser->getId())->first();

        if ($account) {
            return $account->user;
        }

        $email = $socialUser->getEmail() ? mb_strtolower($socialUser->getEmail()) : null;
        $user = $email ? User::findByIdentifier(AuthMethod::Email, $email) : null;

        if ($user && ! $user->isVerified()) {
            // An unverified signup for this email may not belong to the provider's account owner:
            // drop its password so only the verified owner can get in.
            $user->password = null;
        }

        if (! $user) {
            [$first, $last] = array_pad(explode(' ', $this->cleanName($socialUser->getName()), 2), 2, null);

            $user = new User([
                'first_name' => $first ?: null,
                'last_name' => $last,
                'username' => $this->cleanName($socialUser->getName() ?: $socialUser->getNickname()) ?: 'User',
            ]);
            $user->email = $email;
        }

        $user->save();
        $user->socialAccounts()->create(['provider' => $provider, 'provider_id' => $socialUser->getId()]);

        // Providers only hand out addresses they have verified.
        if ($email) {
            $user->markVerified(AuthMethod::Email);
        }

        return $user;
    }

    /**
     * Keep only what the signup form accepts for names: letters, dots and single spaces.
     */
    private function cleanName(?string $name): string
    {
        return trim(preg_replace(['/[^\p{L}. ]+/u', '/\s+/'], ['', ' '], (string) $name));
    }

    /**
     * Apple posts its callback cross-site, where the session cookie is not sent, so it runs stateless.
     */
    private function driver(string $provider)
    {
        $driver = Socialite::driver($provider);

        return $provider === 'apple' ? $driver->stateless() : $driver;
    }

    private function toFrontend(array $query): RedirectResponse
    {
        return redirect()->away(config('auth_flow.frontend_url').config('auth_flow.oauth_callback_path').'?'.http_build_query($query));
    }

    private static function exchangeKey(string $code): string
    {
        return 'oauth-exchange:'.hash('sha256', $code);
    }
}
