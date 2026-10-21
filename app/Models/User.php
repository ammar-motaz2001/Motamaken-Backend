<?php

namespace App\Models;

use App\Enums\AuthMethod;
use App\Services\RewardsService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @property int $id
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string $username
 * @property string|null $email
 * @property Carbon|null $email_verified_at
 * @property string|null $phone
 * @property Carbon|null $phone_verified_at
 * @property string|null $password
 * @property bool $subscribed
 * @property int $points
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $avatar_path
 * @property string|null $locale
 * @property string $currency
 * @property string $theme
 * @property Carbon|null $last_seen_at
 * @property bool $is_admin
 * @property Carbon|null $suspended_at
 * @property string $kyc_status
 * @property string|null $suspension_reason
 * @property string|null $referral_code
 * @property int|null $referred_by
 * @property Carbon|null $referral_held_at
 * @property string|null $two_factor_method
 * @property string|null $two_factor_target
 * @property Carbon|null $two_factor_enabled_at
 * @property array<array-key, mixed>|null $notification_settings
 * @property Carbon|null $password_changed_at
 * @property int|null $admin_role_id
 * @property Carbon|null $suspended_until
 * @property string|null $registration_ip
 * @property-read AdminRole|null $adminRole
 * @property-read mixed $avatar_url
 * @property-read Collection<int, Conversation> $conversations
 * @property-read int|null $conversations_count
 * @property-read mixed $full_name
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read Profile|null $profile
 * @property-read User|null $referrer
 * @property-read Subscription|null $subscription
 * @property-read Collection<int, PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 *
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereAdminRoleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereAvatarPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereFirstName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereIsAdmin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereKycStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereLastName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereLastSeenAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereLocale($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereNotificationSettings($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePasswordChangedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePhoneVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePoints($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereReferralCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereReferredBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRegistrationIp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereSubscribed($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereSuspendedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereSuspendedUntil($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereSuspensionReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereTheme($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereTwoFactorEnabledAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereTwoFactorMethod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereTwoFactorTarget($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUsername($value)
 *
 * @mixin \Eloquent
 */
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
            'suspended_at' => 'datetime',
            'suspended_until' => 'datetime',
            'referral_held_at' => 'datetime',
            'is_admin' => 'boolean',
        ];
    }

    /**
     * Awards the identity medal when KYC is approved, and gives every new user a referral code.
     */
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
     * The plan the user pays for right now, if any.
     *
     * @return HasOne<Subscription, $this>
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->inEffect()->latestOfMany('current_period_end');
    }

    /**
     * The public profile (freelancer, client or influencer), null until created.
     *
     * @return HasOne<Profile, $this>
     */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    /**
     * The conversations the user takes part in.
     *
     * @return BelongsToMany<Conversation, $this>
     */
    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_user');
    }

    /**
     * The user with this email or phone (whichever the method uses), or null.
     */
    public static function findByIdentifier(AuthMethod $method, string $identifier): ?self
    {
        return static::where($method->column(), $identifier)->first();
    }

    /**
     * First and last name, or the username when neither was given.
     *
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim("{$this->first_name} {$this->last_name}") ?: $this->username);
    }

    /**
     * Public URL of the profile photo, or null without one.
     *
     * @return Attribute<string|null, never>
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::get(fn () => $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null);
    }

    /**
     * Whether the user turned on two-factor sign-in.
     */
    public function hasTwoFactor(): bool
    {
        return $this->two_factor_method !== null;
    }

    /**
     * The channel the two-factor code is sent through, null when it is off.
     */
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

    /**
     * Whether the user was active within the last few minutes (ONLINE_WINDOW_MINUTES).
     */
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

    /**
     * The staff role. Null on an admin means full access.
     *
     * @return BelongsTo<AdminRole, $this>
     */
    public function adminRole(): BelongsTo
    {
        return $this->belongsTo(AdminRole::class);
    }

    /**
     * Whether this admin may use a part of the panel (config/admin.php permissions).
     */
    public function canAdmin(string $permission): bool
    {
        return $this->is_admin && ($this->admin_role_id === null || (bool) $this->adminRole?->allows($permission));
    }

    /**
     * The panel permissions of this admin ("*" for all), empty for everyone else.
     *
     * @return list<string>
     */
    public function adminPermissions(): array
    {
        if (! $this->is_admin) {
            return [];
        }

        $granted = $this->admin_role_id === null ? ['*'] : ($this->adminRole?->permissions ?? []);

        return in_array('*', $granted, true) ? config('admin.permissions') : array_values($granted);
    }

    /**
     * Suspended right now: a ban (no end date) or a suspension that has not run out yet.
     */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null && ($this->suspended_until === null || $this->suspended_until->isFuture());
    }

    /**
     * The account type the user acts as: `influencer`, `freelancer` (by profile) or `client` (no profile).
     */
    public function accountType(): string
    {
        return $this->profile?->type ?? 'client';
    }
}
