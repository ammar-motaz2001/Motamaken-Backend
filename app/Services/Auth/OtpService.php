<?php

namespace App\Services\Auth;

use App\Enums\AuthMethod;
use App\Enums\OtpPurpose;
use App\Notifications\OtpCode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * One-time codes are kept in the cache (Redis) per purpose + identifier.
 *
 * A record outlives its code so that "resend" keeps working after the code itself expired,
 * but a resend is only possible when a code was issued by signup, login or forgot-password.
 */
class OtpService
{
    private const RECORD_TTL_MINUTES = 60;

    public function send(OtpPurpose $purpose, AuthMethod $method, string $target): void
    {
        $record = Cache::get($this->key($purpose, $method, $target));

        // Repeated form submits inside the cooldown keep the code that is already on its way.
        if ($record && now()->timestamp - $record['sent_at'] < config('auth_flow.otp.resend_cooldown')) {
            return;
        }

        $this->issue($purpose, $method, $target);
    }

    /**
     * @return bool false when there is no pending code for this identifier
     */
    public function resend(OtpPurpose $purpose, AuthMethod $method, string $target): bool
    {
        $record = Cache::get($this->key($purpose, $method, $target));

        if (! $record) {
            return false;
        }

        $wait = config('auth_flow.otp.resend_cooldown') - (now()->timestamp - $record['sent_at']);

        abort_if($wait > 0, 429, __('auth_flow.otp_resend_wait', ['seconds' => $wait]));

        $this->issue($purpose, $method, $target);

        return true;
    }

    /**
     * Check a code and consume it on success. Aborts with a user-facing message on failure.
     */
    public function verify(OtpPurpose $purpose, AuthMethod $method, string $target, string $code): void
    {
        $key = $this->key($purpose, $method, $target);
        $record = Cache::get($key);

        abort_if(! $record || now()->timestamp > $record['expires_at'], 422, __('auth_flow.otp_expired'));

        if (! hash_equals($record['hash'], $this->hash($key, $code))) {
            $record['attempts']++;

            if ($record['attempts'] >= config('auth_flow.otp.max_attempts')) {
                $record['expires_at'] = 0;
                Cache::put($key, $record, now()->addMinutes(self::RECORD_TTL_MINUTES));
                abort(429, __('auth_flow.otp_too_many_attempts'));
            }

            Cache::put($key, $record, now()->addMinutes(self::RECORD_TTL_MINUTES));
            abort(422, __('auth_flow.otp_invalid'));
        }

        Cache::forget($key);
    }

    private function issue(OtpPurpose $purpose, AuthMethod $method, string $target): void
    {
        $key = $this->key($purpose, $method, $target);
        $length = config('auth_flow.otp.length');
        $code = str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);

        Cache::put($key, [
            'hash' => $this->hash($key, $code),
            'attempts' => 0,
            'sent_at' => now()->timestamp,
            'expires_at' => now()->addMinutes(config('auth_flow.otp.ttl'))->timestamp,
        ], now()->addMinutes(self::RECORD_TTL_MINUTES));

        Notification::route($method === AuthMethod::Email ? 'mail' : 'whatsapp', $target)
            ->notify((new OtpCode($code, $purpose))->locale(app()->getLocale()));
    }

    private function key(OtpPurpose $purpose, AuthMethod $method, string $target): string
    {
        return "otp:{$purpose->value}:{$method->value}:".sha1(mb_strtolower($target));
    }

    private function hash(string $key, string $code): string
    {
        return hash_hmac('sha256', $key.'|'.$code, config('app.key'));
    }
}
