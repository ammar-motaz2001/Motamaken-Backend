<?php

namespace App\Http\Controllers;

use App\Enums\AuthMethod;
use App\Enums\OtpPurpose;
use App\Exceptions\ApiException;
use App\Services\Auth\OtpService;
use App\Support\Mask;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;
use Propaganistas\LaravelPhone\PhoneNumber;

#[Group('Settings', 'Account settings: profile, password, notifications, two-factor authentication, login devices.', weight: 5)]
class SecurityController extends Controller
{
    private const PENDING_MINUTES = 15;

    public function __construct(private readonly OtpService $otp) {}

    /**
     * Account security.
     *
     * Two-factor status and the devices signed in to the account (newest activity first, the current one flagged).
     *
     * @return array{twoFactor: array{enabled: bool, method: ?string, target: ?string, enabledAt: mixed}, passwordChangedAt: mixed, sessions: list<array<string, mixed>>}
     */
    public function show(Request $request): array
    {
        $user = $request->user();
        $current = $user->currentAccessToken()?->getKey();

        return [
            'twoFactor' => [
                'enabled' => $user->hasTwoFactor(),
                /** `email` or `phone`. */
                'method' => $user->two_factor_method,
                /** Masked, e.g. `•••••••••255`. */
                'target' => $user->hasTwoFactor() ? Mask::identifier($user->twoFactorMethod(), $user->two_factor_target) : null,
                'enabledAt' => $user->two_factor_enabled_at,
            ],
            'passwordChangedAt' => $user->password_changed_at,
            'sessions' => $user->tokens()->orderByDesc('last_used_at')->orderByDesc('id')->get()
                ->map(fn (PersonalAccessToken $token) => [
                    'id' => $token->id,
                    /** Parsed from the browser's user agent, e.g. `Chrome on macOS`. */
                    'device' => $this->device($token->user_agent),
                    'ip' => $token->ip_address,
                    'lastUsedAt' => $token->last_used_at ?? $token->created_at,
                    'createdAt' => $token->created_at,
                    'isCurrent' => $token->id === $current,
                ])->values()->all(),
        ];
    }

    /**
     * Start enabling two-factor authentication.
     *
     * Checks the account password, then sends a code to the chosen email or phone (`target`). Confirm it with
     * `POST /me/two-factor/confirm`.
     *
     * @return array{sent: bool, target: string}
     */
    public function start(Request $request): array
    {
        $data = $request->validate([
            /** `email` or `phone`. */
            'method' => ['required', Rule::enum(AuthMethod::class)],
            'password' => ['required', 'string', 'current_password:sanctum'],
            /** The email address, or the phone number in international format (`+966501234567`). */
            'target' => ['required', 'string', 'max:255', $request->input('method') === 'phone' ? 'phone:INTERNATIONAL' : 'email'],
        ]);

        $method = AuthMethod::from($data['method']);
        $target = $method === AuthMethod::Email ? mb_strtolower(trim($data['target'])) : (new PhoneNumber($data['target']))->formatE164();

        Cache::put($this->pendingKey($request), ['method' => $method->value, 'target' => $target], now()->addMinutes(self::PENDING_MINUTES));
        $this->otp->send(OtpPurpose::TwoFactor, $method, $target);

        return ['sent' => true, 'target' => Mask::identifier($method, $target)];
    }

    /**
     * Send the setup code again.
     *
     * @return array{sent: bool}
     */
    public function resend(Request $request): array
    {
        $pending = $this->pending($request);
        $this->otp->resend(OtpPurpose::TwoFactor, AuthMethod::from($pending['method']), $pending['target']);

        return ['sent' => true];
    }

    /**
     * Confirm two-factor authentication.
     *
     * With the code that was sent. From now on every login also asks for a code sent there.
     */
    public function confirm(Request $request): array
    {
        $request->validate(['code' => ['required', 'string', 'regex:/^\d{4,8}$/']]);
        $pending = $this->pending($request);
        $method = AuthMethod::from($pending['method']);
        $user = $request->user();

        $this->otp->verify(OtpPurpose::TwoFactor, $method, $pending['target'], $request->input('code'));
        Cache::forget($this->pendingKey($request));

        $user->forceFill(['two_factor_method' => $method->value, 'two_factor_target' => $pending['target'], 'two_factor_enabled_at' => now()])->save();
        if ($user->{$method->column()} === $pending['target']) {
            $user->markVerified($method);
        }

        return $this->show($request);
    }

    /**
     * Turn off two-factor authentication.
     *
     * Needs the account password.
     */
    public function disable(Request $request): array
    {
        $request->validate(['password' => ['required', 'string', 'current_password:sanctum']]);

        $request->user()->forceFill(['two_factor_method' => null, 'two_factor_target' => null, 'two_factor_enabled_at' => null])->save();

        return $this->show($request);
    }

    /**
     * Log out a device.
     *
     * @return array{success: bool, wasCurrent: bool}
     */
    public function revoke(Request $request, int $session): array
    {
        $token = $request->user()->tokens()->findOrFail($session);
        $wasCurrent = $token->id === $request->user()->currentAccessToken()?->getKey();
        $token->delete();

        return ['success' => true, 'wasCurrent' => $wasCurrent];
    }

    /**
     * Log out every other device.
     *
     * @return array{success: bool, count: int}
     */
    public function revokeOthers(Request $request): array
    {
        $count = $request->user()->tokens()->whereKeyNot($request->user()->currentAccessToken()?->getKey())->delete();

        return ['success' => true, 'count' => $count];
    }

    /**
     * @return array{method: string, target: string}
     */
    private function pending(Request $request): array
    {
        return Cache::get($this->pendingKey($request)) ?? throw new ApiException(__('auth_flow.two_factor_expired'), 'two_factor_expired');
    }

    private function pendingKey(Request $request): string
    {
        return "two_factor_setup:{$request->user()->id}";
    }

    private function device(?string $agent): string
    {
        if (! $agent) {
            return __('auth_flow.unknown_device');
        }

        $browser = collect(['Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome', 'Firefox' => 'Firefox', 'Safari' => 'Safari'])
            ->first(fn ($name, $needle) => str_contains($agent, $needle)) ?? __('auth_flow.unknown_browser');
        $system = collect(['iPhone' => 'iOS', 'iPad' => 'iPadOS', 'Android' => 'Android', 'Mac OS X' => 'macOS', 'Windows' => 'Windows', 'Linux' => 'Linux'])
            ->first(fn ($name, $needle) => str_contains($agent, $needle)) ?? __('auth_flow.unknown_system');

        return __('auth_flow.device', ['browser' => $browser, 'system' => $system]);
    }
}
