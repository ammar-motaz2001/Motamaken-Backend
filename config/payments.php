<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Payment gateway
    |--------------------------------------------------------------------------
    |
    | "fake" accepts every payment and is for development only. A real gateway (Stripe, HyperPay, Tap, ...)
    | implements App\Contracts\PaymentGateway and is bound in AppServiceProvider.
    |
    */

    'driver' => env('PAYMENTS_DRIVER', 'fake'),

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

    'deposit_methods' => ['card', 'bank_transfer', 'apple_pay', 'mada'],

    'withdrawal_methods' => ['bank_transfer', 'paypal'],

];
