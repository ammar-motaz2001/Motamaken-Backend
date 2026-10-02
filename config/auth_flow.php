<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Frontend
    |--------------------------------------------------------------------------
    |
    | The Next.js app. OAuth logins finish by redirecting the browser to
    | "{url}{oauth_callback_path}?code=..." (or "?error=...").
    |
    */

    'frontend_url' => rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/'),

    'oauth_callback_path' => env('FRONTEND_OAUTH_CALLBACK_PATH', '/oauth/callback'),

    /*
    |--------------------------------------------------------------------------
    | One-time codes
    |--------------------------------------------------------------------------
    |
    | The frontend OTP input has exactly 4 boxes and a 59 second resend timer.
    |
    */

    'otp' => [
        'length' => 4,
        'ttl' => (int) env('OTP_TTL_MINUTES', 10),
        'resend_cooldown' => 60,
        'max_attempts' => 5,
    ],

    // Login methods that require an OTP after the password. Unverified accounts always require one.
    'login_otp_methods' => array_filter(explode(',', env('LOGIN_OTP_METHODS', 'email'))),

    // Lifetime of the token handed out after a "reset" OTP is verified.
    'reset_token_ttl' => 15,

    // Points granted when an account is verified for the first time (shown on the signup success modal).
    'welcome_points' => 50,

    'oauth_providers' => ['google', 'apple', 'x', 'facebook'],

];
