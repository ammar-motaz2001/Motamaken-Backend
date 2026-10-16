<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Frontend
    |--------------------------------------------------------------------------
    |
    | The Next.js app (links in emails and notifications point here).
    |
    */

    'frontend_url' => rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/'),

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

    // Login methods that always require an OTP after the password (comma separated, e.g. "email,phone"). Empty by default:
    // a verified account logs in with its password. Unverified accounts and accounts with two-factor on always get a code.
    'login_otp_methods' => array_filter(explode(',', (string) env('LOGIN_OTP_METHODS', ''))),

    // Lifetime of the token handed out after a "reset" OTP is verified.
    'reset_token_ttl' => 15,

    // Points granted when an account is verified for the first time (shown on the signup success modal).
    'welcome_points' => 50,

];
