<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Payment gateway
    |--------------------------------------------------------------------------
    |
    | "moyasar" charges real cards through Moyasar (test keys charge nothing). "fake" accepts every payment
    | and is for development only. Both implement App\Contracts\PaymentGateway (bound in AppServiceProvider).
    |
    */

    'driver' => env('PAYMENTS_DRIVER', 'fake'),

    'moyasar' => [
        'api_url' => env('MOYASAR_API_URL', 'https://api.moyasar.com/v1'),
        // The keys in use. Secret key (sk_...): server only. The publishable key (pk_...) is public: the website gets it
        // from GET /site-settings. Admins switch between test and live in Admin › Integrations (App\Support\MoyasarMode).
        'secret_key' => env('MOYASAR_SECRET_KEY'),
        'publishable_key' => env('MOYASAR_PUBLISHABLE_KEY', env('NEXT_PUBLIC_MOYASAR_PUBLISHABLE_KEY')),
        // The "secret token" set on the webhook in the Moyasar dashboard; it is sent inside every webhook body.
        'webhook_secret' => env('MOYASAR_WEBHOOK_SECRET'),
        // Both modes' keys, so admins can switch without editing the server. Each pair is optional.
        'keys' => [
            'test' => ['secret_key' => env('MOYASAR_TEST_SECRET_KEY'), 'publishable_key' => env('MOYASAR_TEST_PUBLISHABLE_KEY'), 'webhook_secret' => env('MOYASAR_TEST_WEBHOOK_SECRET')],
            'live' => ['secret_key' => env('MOYASAR_LIVE_SECRET_KEY'), 'publishable_key' => env('MOYASAR_LIVE_PUBLISHABLE_KEY'), 'webhook_secret' => env('MOYASAR_LIVE_WEBHOOK_SECRET')],
        ],
        // The single key pair above as it was in the environment, before an admin picked a mode.
        'legacy' => [
            'secret_key' => env('MOYASAR_SECRET_KEY'),
            'publishable_key' => env('MOYASAR_PUBLISHABLE_KEY', env('NEXT_PUBLIC_MOYASAR_PUBLISHABLE_KEY')),
            'webhook_secret' => env('MOYASAR_WEBHOOK_SECRET'),
        ],
        // Website page Moyasar sends the customer back to after 3-D Secure.
        'return_path' => env('MOYASAR_RETURN_PATH', '/payments/return'),
    ],

    // Shared secret that signs the gateway's webhook calls (HMAC-SHA256 of the raw body, in the X-Signature header).
    'webhook_secret' => env('PAYMENTS_WEBHOOK_SECRET', 'change-me-in-production'),

    /*
    |--------------------------------------------------------------------------
    | Defaults for the settings admins can change at runtime
    |--------------------------------------------------------------------------
    */

    'defaults' => [
        // The platform's cut of every released milestone, in percent ("Site fees").
        'fees.site_percent' => 10,
        'deposits.min' => 5,
        'deposits.max' => 10000,
        'withdrawals.min' => 20,
        // Flat fee charged on top of every withdrawal.
        'withdrawals.fee' => 1,
        'withdrawals.require_kyc' => true,
    ],

    // Offered methods, in display order. Admins can switch some off and reorder them (Settings > Payments).
    'deposit_methods' => ['card', 'bank_transfer', 'apple_pay', 'mada'],

    'withdrawal_methods' => ['bank_transfer', 'paypal'],

    // Every method the code supports: what the admins choose from.
    'deposit_method_choices' => ['card', 'mada', 'apple_pay', 'bank_transfer'],

    'withdrawal_method_choices' => ['bank_transfer', 'paypal'],

];
