<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // reCAPTCHA v3 (invisible, scored) on sign-up, quick sign-in and the contact form. Without a secret it is switched off.
    'recaptcha' => [
        'site_key' => env('RECAPTCHA_SITE_KEY'),
        'secret_key' => env('RECAPTCHA_SECRET_KEY'),
        // v3 scores each visit from 0 (bot) to 1 (person); below this the request is refused.
        'min_score' => (float) env('RECAPTCHA_MIN_SCORE', 0.5),
    ],

    // Shared with the website's API proxy: lets it pass on the visitor's IP, browser and country (TrustFrontendProxy).
    'frontend_proxy' => [
        'secret' => env('FRONTEND_PROXY_SECRET'),
    ],

];
