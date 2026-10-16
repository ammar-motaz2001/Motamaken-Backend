<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['slug', 'section', 'title_en', 'title_ar', 'body_en', 'body_ar', 'is_published', 'sort_order', 'updated_by'])]
class Page extends Model
{
    public const SECTIONS = ['about', 'help', 'legal'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'sort_order' => 'integer'];
    }

    public function title(): string
    {
        return app()->getLocale() === 'ar' ? $this->title_ar : $this->title_en;
    }

    /**
     * The body in the request language, with the live numbers filled in.
     */
    public function body(): string
    {
        return strtr(app()->getLocale() === 'ar' ? $this->body_ar : $this->body_en, self::placeholders());
    }

    /**
     * What `{{...}}` in a page body is replaced with. Keeps fee pages true when admins change the settings.
     *
     * @return array<string, string>
     */
    public static function placeholders(): array
    {
        $number = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');

        return [
            '{{site_fee}}' => $number(Setting::get('fees.site_percent')),
            '{{withdrawal_fee}}' => $number(Setting::get('withdrawals.fee')),
            '{{withdrawal_min}}' => $number(Setting::get('withdrawals.min')),
            '{{deposit_min}}' => $number(Setting::get('deposits.min')),
            '{{campaign_fee}}' => $number(config('marketplace.campaigns.fee_percent')),
            '{{free_offers}}' => (string) config('marketplace.subscriptions.free_monthly_offers'),
            '{{site_name}}' => (string) config('app.name'),
        ];
    }
}
