<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\City;
use App\Models\Country;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Data the site needs to work at all (dropdowns, categories). Safe to run again: it only fills what is missing.
 */
class ReferenceDataSeeder extends Seeder
{
    /** @var array<string, array{0: string, 1: array<int, array{0: string, 1: string}>}> code => [Arabic name, cities] */
    private const COUNTRIES = [
        'Egypt' => ['EG', 'مصر', [['Cairo', 'القاهرة'], ['Alexandria', 'الإسكندرية'], ['Giza', 'الجيزة']]],
        'Saudi Arabia' => ['SA', 'السعودية', [['Riyadh', 'الرياض'], ['Jeddah', 'جدة'], ['Dammam', 'الدمام']]],
        'United Arab Emirates' => ['AE', 'الإمارات', [['Dubai', 'دبي'], ['Abu Dhabi', 'أبو ظبي'], ['Sharjah', 'الشارقة']]],
        'Kuwait' => ['KW', 'الكويت', [['Kuwait City', 'مدينة الكويت']]],
        'Qatar' => ['QA', 'قطر', [['Doha', 'الدوحة']]],
        'Bahrain' => ['BH', 'البحرين', [['Manama', 'المنامة']]],
        'Oman' => ['OM', 'عُمان', [['Muscat', 'مسقط']]],
        'Jordan' => ['JO', 'الأردن', [['Amman', 'عمّان']]],
        'Lebanon' => ['LB', 'لبنان', [['Beirut', 'بيروت']]],
        'Morocco' => ['MA', 'المغرب', [['Casablanca', 'الدار البيضاء'], ['Rabat', 'الرباط']]],
        'Armenia' => ['AM', 'أرمينيا', [['Yerevan', 'يريفان']]],
        'Argentina' => ['AR', 'الأرجنتين', [['Buenos Aires', 'بوينس آيرس']]],
    ];

    /** @var array<int, array{0: string, 1: string, 2: string, 3: string}> slug, English, Arabic, icon */
    private const PROJECT_CATEGORIES = [
        ['website-it-software', 'Website, IT & Software', 'مواقع وتقنية معلومات وبرمجيات', 'laptop'],
        ['design-media-architecture', 'Design, Media & Architecture', 'التصميم والوسائط والعمارة', 'brush'],
        ['mobile-phone-computing', 'Mobile Phone & Computing', 'الجوال والحاسوب', 'smartphone'],
        ['writing-content', 'Writing Content', 'الكتابة والمحتوى', 'pen'],
        ['data-entry-admin', 'Data Entry & Admin', 'إدخال البيانات والإدارة', 'keyboard'],
        ['engineering-science', 'Engineering & Science', 'الهندسة والعلوم', 'flask'],
    ];

    /** @var array<int, array{0: string, 1: string, 2: string}> slug, English, Arabic */
    private const PLATFORMS = [
        ['instagram', 'Instagram', 'انستغرام'],
        ['facebook', 'Facebook', 'فيسبوك'],
        ['youtube', 'YouTube', 'يوتيوب'],
        ['tiktok', 'TikTok', 'تيك توك'],
        ['snapchat', 'Snapchat', 'سناب شات'],
        ['x', 'X', 'إكس'],
        ['pinterest', 'Pinterest', 'بنترست'],
        ['telegram', 'Telegram', 'تيليجرام'],
        ['whatsapp', 'WhatsApp', 'واتساب'],
    ];

    /** @var array<int, array<string, mixed>> */
    private const PLANS = [
        ['freelancer-free', 'freelancer', 'Free', 'مجاني', 0, [], 0, false],
        ['freelancer-pro', 'freelancer', 'Pro', 'احترافي', 1699, ['monthly_offers' => 50, 'fee_percent' => 8, 'promotion_discount_percent' => 10], 1, true],
        ['freelancer-elite', 'freelancer', 'Elite', 'نخبة', 3999, ['monthly_offers' => null, 'fee_percent' => 5, 'promotion_discount_percent' => 25, 'priority_support' => true], 2, false],
        ['client-free', 'client', 'Free', 'مجاني', 0, [], 0, false],
        ['client-business', 'client', 'Business', 'أعمال', 2999, ['monthly_offers' => 15, 'promotion_discount_percent' => 20, 'priority_support' => true], 1, true],
        ['influencer-free', 'influencer', 'Free', 'مجاني', 0, [], 0, false],
        ['influencer-pro', 'influencer', 'Pro', 'احترافي', 999, ['monthly_offers' => 50, 'fee_percent' => 8, 'promotion_discount_percent' => 10], 1, false],
        ['influencer-elite', 'influencer', 'Elite', 'نخبة', 4999, ['monthly_offers' => null, 'fee_percent' => 5, 'promotion_discount_percent' => 25, 'priority_support' => true], 2, true],
    ];

    public function run(): void
    {
        foreach (self::PLANS as [$code, $audience, $english, $arabic, $price, $features, $order, $popular]) {
            Plan::updateOrCreate(['code' => $code], [
                'audience' => $audience, 'name_en' => $english, 'name_ar' => $arabic, 'price_cents' => $price,
                'features' => $features, 'sort_order' => $order, 'is_popular' => $popular,
            ]);
        }

        foreach (self::COUNTRIES as $english => [$code, $arabic, $cities]) {
            $country = Country::firstOrCreate(['code' => $code], ['name_en' => $english, 'name_ar' => $arabic]);

            foreach ($cities as [$cityEnglish, $cityArabic]) {
                City::firstOrCreate(
                    ['country_id' => $country->id, 'name_en' => $cityEnglish],
                    ['name_ar' => $cityArabic],
                );
            }
        }

        foreach (self::PROJECT_CATEGORIES as $order => [$slug, $english, $arabic, $icon]) {
            Category::updateOrCreate(
                ['type' => Category::TYPE_PROJECT, 'slug' => $slug],
                ['name_en' => $english, 'name_ar' => $arabic, 'icon' => $icon, 'sort_order' => $order],
            );
        }

        foreach (self::PLATFORMS as $order => [$slug, $english, $arabic]) {
            Category::updateOrCreate(
                ['type' => Category::TYPE_INFLUENCER, 'slug' => $slug],
                ['name_en' => $english, 'name_ar' => $arabic, 'icon' => $slug, 'sort_order' => $order],
            );
        }
    }
}
