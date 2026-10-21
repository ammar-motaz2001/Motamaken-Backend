<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $audience
 * @property string $name_en
 * @property string $name_ar
 * @property int $price_cents
 * @property string $currency
 * @property array<array-key, mixed> $features
 * @property bool $is_popular
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read mixed $name
 * @property-read Collection<int, Subscription> $subscriptions
 * @property-read int|null $subscriptions_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereAudience($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereFeatures($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereIsPopular($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereNameAr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereNameEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan wherePriceCents($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['code', 'audience', 'name_en', 'name_ar', 'price_cents', 'currency', 'features', 'is_popular', 'is_active', 'sort_order'])]
class Plan extends Model
{
    use HasFactory, HasLocalizedName;

    public const AUDIENCES = ['freelancer', 'client', 'influencer'];

    /** @var array<string, mixed> */
    protected $attributes = ['currency' => 'USD', 'price_cents' => 0, 'is_active' => true, 'is_popular' => false];

    /**
     * Features come back as a list, the price as whole cents, the flags as booleans.
     */
    protected function casts(): array
    {
        return ['features' => 'array', 'price_cents' => 'integer', 'is_popular' => 'boolean', 'is_active' => 'boolean'];
    }

    /**
     * Whether the plan costs nothing, so subscribing to it needs no payment.
     */
    public function isFree(): bool
    {
        return $this->price_cents === 0;
    }

    /**
     * The benefits as sentences in the request language, for the plan card.
     *
     * @return list<string>
     */
    public function perks(): array
    {
        $features = $this->features;
        $perks = [];

        $perks[] = array_key_exists('monthly_offers', $features) && $features['monthly_offers'] === null
            ? __('subscriptions.perks.unlimited_offers')
            : __('subscriptions.perks.offers', ['count' => $features['monthly_offers'] ?? config('marketplace.subscriptions.free_monthly_offers')]);

        if (isset($features['fee_percent'])) {
            $perks[] = __('subscriptions.perks.fee', ['percent' => $features['fee_percent']]);
        }

        if (! empty($features['promotion_discount_percent'])) {
            $perks[] = __('subscriptions.perks.promotion_discount', ['percent' => $features['promotion_discount_percent']]);
        }

        if (! empty($features['priority_support'])) {
            $perks[] = __('subscriptions.perks.priority_support');
        }

        return $perks;
    }

    /**
     * Every subscription to this plan, past and current.
     *
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
