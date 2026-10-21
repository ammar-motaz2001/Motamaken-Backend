<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * @property int $id
 * @property string $type
 * @property string $value
 * @property string $reason
 * @property int|null $created_by
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $creator
 *
 * @method static Builder<static>|BlacklistEntry inForce()
 * @method static Builder<static>|BlacklistEntry newModelQuery()
 * @method static Builder<static>|BlacklistEntry newQuery()
 * @method static Builder<static>|BlacklistEntry query()
 * @method static Builder<static>|BlacklistEntry whereCreatedAt($value)
 * @method static Builder<static>|BlacklistEntry whereCreatedBy($value)
 * @method static Builder<static>|BlacklistEntry whereExpiresAt($value)
 * @method static Builder<static>|BlacklistEntry whereId($value)
 * @method static Builder<static>|BlacklistEntry whereReason($value)
 * @method static Builder<static>|BlacklistEntry whereType($value)
 * @method static Builder<static>|BlacklistEntry whereUpdatedAt($value)
 * @method static Builder<static>|BlacklistEntry whereValue($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['type', 'value', 'reason', 'created_by', 'expires_at'])]
class BlacklistEntry extends Model
{
    public const TYPES = ['email', 'phone', 'domain', 'ip', 'keyword'];

    /**
     * expires_at becomes a date; null means the entry never expires.
     */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /**
     * Any save or delete clears the cached blacklist, so the change applies on the next check.
     */
    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('blacklist'));
        static::deleted(fn () => Cache::forget('blacklist'));
    }

    /**
     * Keep the entries that have no expiry or have not expired yet.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInForce(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * The admin who added the entry.
     *
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

    /**
     * The form a value is stored and compared in: phones keep only digits and +, domains lose a leading @, the rest is lowercased.
     */
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

    /**
     * Whether the IP address is on the active blacklist. A missing IP is never blocked.
     */
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
