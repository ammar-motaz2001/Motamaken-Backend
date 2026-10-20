<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

#[Fillable(['type', 'value', 'reason', 'created_by', 'expires_at'])]
class BlacklistEntry extends Model
{
    public const TYPES = ['email', 'phone', 'domain', 'ip', 'keyword'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('blacklist'));
        static::deleted(fn () => Cache::forget('blacklist'));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInForce(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The entries in force, grouped by type (cached for a minute: checked on every sign-up and post).
     *
     * @return array<string, list<string>>
     */
    public static function active(): array
    {
        return Cache::remember('blacklist', 60, fn () => static::inForce()->get(['type', 'value'])
            ->groupBy('type')->map(fn ($rows) => $rows->pluck('value')->all())->all());
    }

    public static function normalize(string $type, string $value): string
    {
        $value = trim($value);

        return match ($type) {
            'phone' => preg_replace('/[^\d+]/', '', $value),
            'domain' => ltrim(mb_strtolower($value), '@'),
            default => mb_strtolower($value),
        };
    }

    /**
     * Whether an email or phone (or the email's domain) is blocked.
     */
    public static function blocksIdentifier(?string $identifier): bool
    {
        if (! $identifier) {
            return false;
        }

        $list = static::active();

        if (str_contains($identifier, '@')) {
            $email = static::normalize('email', $identifier);

            return in_array($email, $list['email'] ?? [], true)
                || in_array(substr(strrchr($email, '@'), 1), $list['domain'] ?? [], true);
        }

        return in_array(static::normalize('phone', $identifier), $list['phone'] ?? [], true);
    }

    public static function blocksIp(?string $ip): bool
    {
        return $ip !== null && in_array($ip, static::active()['ip'] ?? [], true);
    }

    /**
     * The first blocked word found in the text, if any.
     */
    public static function blockedWord(?string $text): ?string
    {
        if (! $text) {
            return null;
        }

        $haystack = mb_strtolower($text);

        foreach (static::active()['keyword'] ?? [] as $word) {
            if ($word !== '' && str_contains($haystack, $word)) {
                return $word;
            }
        }

        return null;
    }
}
