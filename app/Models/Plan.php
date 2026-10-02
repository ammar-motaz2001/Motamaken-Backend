<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'audience', 'name_en', 'name_ar', 'price_cents', 'currency', 'features', 'is_popular', 'is_active', 'sort_order'])]
class Plan extends Model
{
    use HasFactory, HasLocalizedName;

    public const AUDIENCES = ['freelancer', 'client', 'influencer'];

    /** @var array<string, mixed> */
    protected $attributes = ['currency' => 'USD', 'price_cents' => 0, 'is_active' => true, 'is_popular' => false];

    protected function casts(): array
    {
        return ['features' => 'array', 'price_cents' => 'integer', 'is_popular' => 'boolean', 'is_active' => 'boolean'];
    }

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
}
