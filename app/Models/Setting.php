<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Values admins can change without a deploy (fees, limits). Defaults live in config/payments.php.
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
        $values = Cache::remember('settings', 60, fn () => static::query()->pluck('value', 'key')->all());

        // The defaults are keyed by names that contain dots, so they are read as a plain array, not as a config path.
        return $values[$key] ?? config('payments.defaults')[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
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
