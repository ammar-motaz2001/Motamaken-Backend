<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $slug
 * @property string $section
 * @property string $title_en
 * @property string $title_ar
 * @property string $body_en
 * @property string $body_ar
 * @property bool $is_published
 * @property int $sort_order
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Page newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Page newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Page query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Page whereBodyAr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Page whereBodyEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Page whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Page whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Page whereIsPublished($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Page whereSection($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Page whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Page whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Page whereTitleAr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Page whereTitleEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Page whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Page whereUpdatedBy($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['slug', 'section', 'title_en', 'title_ar', 'body_en', 'body_ar', 'meta_title_en', 'meta_title_ar', 'meta_description_en',
    'meta_description_ar', 'noindex', 'is_published', 'sort_order', 'updated_by'])]
class Page extends Model
{
    public const SECTIONS = ['about', 'help', 'legal'];

    /**
     * is_published and noindex become booleans, sort_order an integer.
     */
    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'noindex' => 'boolean', 'sort_order' => 'integer'];
    }

    /**
     * Search-engine title and description in the request language; null where the admins left them empty.
     *
     * @return array{title: ?string, description: ?string, noindex: bool}
     */
    public function seo(): array
    {
        $ar = app()->getLocale() === 'ar';

        return [
            'title' => ($ar ? $this->meta_title_ar : $this->meta_title_en) ?: null,
            'description' => ($ar ? $this->meta_description_ar : $this->meta_description_en) ?: null,
            'noindex' => (bool) $this->noindex,
        ];
    }

    /**
     * The title in the request language.
     */
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
