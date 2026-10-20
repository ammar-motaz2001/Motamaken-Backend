<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

/**
 * Values admins can change without a deploy (fees, limits, site details). Defaults live in config/payments.php and
 * config/site.php; some override other config values at boot (App\Support\RuntimeConfig).
 */
class Setting extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'json', 'updated_at' => 'datetime'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            $values = Cache::remember('settings', 60, fn () => static::query()->pluck('value', 'key')->all());
        } catch (QueryException) {
            // No settings table yet (fresh install, health checks before migrating): the defaults apply.
            $values = [];
        }

        // The defaults are keyed by names that contain dots, so they are read as a plain array, not as a config path.
        return array_key_exists($key, $values) ? $values[$key] : (config('payments.defaults')[$key] ?? config('site.defaults')[$key] ?? $default);
    }

    public static function put(string $key, mixed $value): void
    {
        if ($value === null) {
            static::query()->whereKey($key)->delete();
            Cache::forget('settings');

            return;
        }

        static::query()->updateOrInsert(['key' => $key], ['value' => json_encode($value), 'updated_at' => now()]);
        Cache::forget('settings');
    }

    /**
     * @return array<string, mixed>
     */
    public static function everything(): array
    {
        return [...config('payments.defaults'), ...static::query()->pluck('value', 'key')->all()];
    }
}
