<?php

namespace App\Models;

use App\Enums\AuthMethod;
use App\Services\RewardsService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['first_name', 'last_name', 'username', 'email', 'phone', 'password', 'subscribed', 'locale', 'currency', 'theme'])]
#[Hidden(['password', 'remember_token', 'two_factor_target'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** Seen within this many minutes counts as online (the green dot in the chat). */
    public const ONLINE_WINDOW_MINUTES = 2;

    /**
     * The same defaults as the database, so a user created a moment ago looks like one loaded later.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'subscribed' => false,
        'points' => 0,
        'currency' => 'USD',
        'theme' => 'light',
        'kyc_status' => 'none',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'subscribed' => 'boolean',
            'points' => 'integer',
            'two_factor_target' => 'encrypted',
            'two_factor_enabled_at' => 'datetime',
            'notification_settings' => 'array',
            'password_changed_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updated(function (self $user) {
            if ($user->wasChanged('kyc_status') && $user->kyc_status === 'approved') {
                app(RewardsService::class)->award($user, 'identity_verified');
                app(RewardsService::class)->syncMedals($user);
            }
        });

        static::creating(function (self $user) {
            $user->referral_code ??= static::newReferralCode();
        });
    }

    /**
     * A short code for the invite link, unique across users.
     */
    public static function newReferralCode(): string
    {
        do {
            $code = Str::upper(Str::random(8));
        } while (static::where('referral_code', $code)->exists());

        return $code;
    }

    /**
     * The person who invited this user, if any.
     *
     * @return BelongsTo<User, $this>
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referred_by');
    }

    /**
     * @return HasMany<SocialAccount, $this>
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * The plan the user pays for right now, if any.
     *
     * @return HasOne<Subscription, $this>
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->inEffect()->latestOfMany('current_period_end');
    }

    /**
     * @return HasOne<Profile, $this>
     */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    /**
     * @return BelongsToMany<Conversation, $this>
     */
    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_user');
    }

    public static function findByIdentifier(AuthMethod $method, string $identifier): ?self
    {
        return static::where($method->column(), $identifier)->first();
    }

    /**
     * First and last name, or the username when neither was given.
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim("{$this->first_name} {$this->last_name}") ?: $this->username);
    }

    protected function avatarUrl(): Attribute
    {
        return Attribute::get(fn () => $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null);
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_method !== null;
    }

    public function twoFactorMethod(): ?AuthMethod
    {
        return $this->two_factor_method ? AuthMethod::from($this->two_factor_method) : null;
    }

    /**
     * Whether notifications of this group are also emailed. Groups follow config('notifications.groups').
     */
    public function wantsEmailFor(string $group): bool
    {
        return (bool) ($this->notification_settings[$group] ?? config("notifications.defaults.{$group}", false));
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at?->gt(now()->subMinutes(self::ONLINE_WINDOW_MINUTES)) ?? false;
    }

    /**
     * Whether the user has verified any identifier. Accounts that never did are abandoned signups.
     */
    public function isVerified(): bool
    {
        return $this->email_verified_at !== null || $this->phone_verified_at !== null;
    }

    /**
     * Mark the identifier as verified, granting the welcome points on the first verification.
     */
    public function markVerified(AuthMethod $method): void
    {
        $first = ! $this->isVerified();

        $this->{$method->verifiedColumn()} ??= now();
        $this->save();

        if ($first) {
            app(RewardsService::class)->onVerified($this);
        }
    }
}
