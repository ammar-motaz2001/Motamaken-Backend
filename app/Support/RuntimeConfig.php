<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Settings the admins changed in the panel that replace config values, so the code reading config() needs no change.
 * Applied when the app boots and before every queued job (workers live long and must see changes too).
 */
class RuntimeConfig
{
    /** Setting key => config path. */
    public const MAP = [
        'promotions.prices' => 'marketplace.promotions.prices',
        'promotions.packages' => 'marketplace.promotions.packages',
        'promotions.points' => 'marketplace.promotions.points',
        'points.rules' => 'engagement.points',
        'payments.deposit_methods' => 'payments.deposit_methods',
        'payments.withdrawal_methods' => 'payments.withdrawal_methods',
        'mail.host' => 'mail.mailers.smtp.host',
        'mail.port' => 'mail.mailers.smtp.port',
        'mail.username' => 'mail.mailers.smtp.username',
        'mail.scheme' => 'mail.mailers.smtp.scheme',
        'mail.from_address' => 'mail.from.address',
        'mail.from_name' => 'mail.from.name',
    ];

    /**
     * Copy the saved settings over their config values (and turn on SMTP mail when enabled).
     * Does nothing while there is no database yet.
     */
    public static function apply(): void
    {
        try {
            // The same cache entry as Setting::get().
            $values = Cache::remember('settings', 60, fn () => Setting::query()->pluck('value', 'key')->all());
        } catch (Throwable) {
            // No database yet (first migration, build step).
            return;
        }

        foreach (self::MAP as $key => $path) {
            if (array_key_exists($key, $values) && $values[$key] !== null) {
                config([$path => $values[$key]]);
            }
        }

        if (! empty($values['mail.password'])) {
            try {
                config(['mail.mailers.smtp.password' => Crypt::decryptString($values['mail.password'])]);
            } catch (Throwable) {
                // Saved with another APP_KEY: keep the server's password.
            }
        }

        if (! empty($values['mail.enabled']) && config('mail.default') !== 'array') {
            config(['mail.default' => 'smtp']);
        }

        if (! empty($values['payments.moyasar_mode'])) {
            MoyasarMode::apply($values['payments.moyasar_mode']);
        }

        if (isset($values['uploads.chat_mb'])) {
            config(['marketplace.chat.max_file_kb' => (int) $values['uploads.chat_mb'] * 1024]);
        }
    }

    /**
     * The upload limit for a kind of file, in KB (for the `max:` validation rule).
     */
    public static function uploadKb(string $kind): int
    {
        return (int) Setting::get("uploads.{$kind}_mb", 10) * 1024;
    }

    /**
     * Allowed extensions for chat, dispute and support attachments.
     */
    public static function attachmentTypes(): string
    {
        return implode(',', Setting::get('uploads.attachment_types'));
    }
}
