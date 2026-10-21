<?php

namespace App\Support;

/**
 * Moyasar's test and live modes. The keys of both stay in the server's environment (never in the database); admins
 * choose which pair is used from Admin › Integrations, and it is applied through RuntimeConfig.
 */
class MoyasarMode
{
    public const MODES = ['test', 'live'];

    /**
     * The mode a secret key belongs to (sk_live_… or sk_test_…), or null without a key.
     */
    public static function of(?string $secretKey): ?string
    {
        return $secretKey ? (str_starts_with($secretKey, 'sk_live') ? 'live' : 'test') : null;
    }

    /**
     * The mode in use now.
     */
    public static function current(): ?string
    {
        return self::of(config('payments.moyasar.secret_key'));
    }

    /**
     * The modes the server has a secret key for: the per-mode keys plus the single MOYASAR_SECRET_KEY.
     *
     * @return list<string>
     */
    public static function available(): array
    {
        $legacy = self::of(config('payments.moyasar.legacy.secret_key'));

        return array_values(array_filter(self::MODES, fn (string $mode) => filled(config("payments.moyasar.keys.{$mode}.secret_key")) || $legacy === $mode));
    }

    /**
     * Use the keys of a mode. Does nothing when the server has no key for it, so a wrong setting cannot break payments.
     */
    public static function apply(?string $mode): void
    {
        if (! in_array($mode, self::MODES, true)) {
            return;
        }

        $keys = config("payments.moyasar.keys.{$mode}");
        if (filled($keys['secret_key'] ?? null)) {
            config(['payments.moyasar.secret_key' => $keys['secret_key'], 'payments.moyasar.publishable_key' => $keys['publishable_key'] ?? null]);
            if (filled($keys['webhook_secret'] ?? null)) {
                config(['payments.moyasar.webhook_secret' => $keys['webhook_secret']]);
            }
        } elseif (self::of(config('payments.moyasar.legacy.secret_key')) === $mode) {
            config(['payments.moyasar.secret_key' => config('payments.moyasar.legacy.secret_key'),
                'payments.moyasar.publishable_key' => config('payments.moyasar.legacy.publishable_key')]);
        }
    }

    /**
     * Every webhook secret token the server knows (both modes), so a test payment's late webhook is still accepted
     * after switching to live. Safe because the payment is always re-read from Moyasar before anything is credited.
     *
     * @return list<string>
     */
    public static function webhookSecrets(): array
    {
        return array_values(array_unique(array_filter([
            config('payments.moyasar.webhook_secret'),
            config('payments.moyasar.keys.test.webhook_secret'),
            config('payments.moyasar.keys.live.webhook_secret'),
            config('payments.moyasar.legacy.webhook_secret'),
        ])));
    }
}
