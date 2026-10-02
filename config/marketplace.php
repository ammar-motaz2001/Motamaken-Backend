<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Header options
    |--------------------------------------------------------------------------
    |
    | The language and currency dropdowns of the site header.
    |
    */

    'languages' => [
        ['code' => 'en', 'label' => 'ENG', 'name' => 'English'],
        ['code' => 'ar', 'label' => 'ARA', 'name' => 'العربية'],
        ['code' => 'fr', 'label' => 'FR', 'name' => 'Français'],
    ],

    'currencies' => [
        ['code' => 'SAR', 'symbol' => 'SAR', 'name_en' => 'Saudi Riyal', 'name_ar' => 'ريال سعودي'],
        ['code' => 'USD', 'symbol' => '$', 'name_en' => 'US Dollar', 'name_ar' => 'دولار أمريكي'],
        ['code' => 'AED', 'symbol' => 'AED', 'name_en' => 'UAE Dirham', 'name_ar' => 'درهم إماراتي'],
        ['code' => 'EUR', 'symbol' => '€', 'name_en' => 'Euro', 'name_ar' => 'يورو'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Homepage
    |--------------------------------------------------------------------------
    |
    | The design shows 6 featured profiles / distinctive projects (a slider of 4 cards at a time)
    | and 5 cards per tab for recent projects and influencer requests.
    |
    */

    'home' => [
        'featured_limit' => 6,
        'tab_limit' => 5,
        'cache_seconds' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscriptions
    |--------------------------------------------------------------------------
    */

    'subscriptions' => [
        // How many offers a user without a paid plan may send per calendar month.
        'free_monthly_offers' => 15,
        // After a failed renewal the benefits last this long, then the subscription expires.
        'grace_days' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Promotions ("Premium Services")
    |--------------------------------------------------------------------------
    |
    | Price per day of placement in a homepage slider, per currency.
    |
    */

    'promotions' => [
        'max_days' => 30,
        'prices' => [
            'profile' => ['USD' => 2, 'SAR' => 7.5, 'AED' => 7.35, 'EUR' => 1.85],
            'project' => ['USD' => 3, 'SAR' => 11.25, 'AED' => 11, 'EUR' => 2.75],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Affiliate programme
    |--------------------------------------------------------------------------
    |
    | A referrer earns `percent` of the platform fee on every payment to someone they invited. The tier depends on how many
    | of their invited people have been paid at least once ("qualified referrals").
    |
    */

    'affiliate' => [
        'tiers' => [
            ['code' => 'bronze', 'min' => 0, 'percent' => 10],
            ['code' => 'silver', 'min' => 10, 'percent' => 20],
            ['code' => 'gold', 'min' => 25, 'percent' => 25],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Chat
    |--------------------------------------------------------------------------
    */

    'chat' => [
        // Kilobytes. Voice messages and attachments share the limit.
        'max_file_kb' => 20480,
        'attachment_url_minutes' => 60,
    ],

];
