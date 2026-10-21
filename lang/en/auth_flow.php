<?php

return [
    'recaptcha_failed' => 'Our security check could not confirm the request. Please try again.',
    'two_factor_expired' => 'The setup took too long. Start again.',
    'unknown_device' => 'Unknown device',
    'unknown_browser' => 'Browser',
    'unknown_system' => 'unknown system',
    'device' => ':browser on :system',
    'already_registered' => [
        'email' => 'An account with this email already exists. Please log in.',
        'phone' => 'An account with this phone number already exists. Please log in.',
    ],
    'no_account' => [
        'email' => 'No account is linked to this email address.',
        'phone' => 'No account is linked to this phone number.',
    ],
    'password_login_required' => 'This account uses two-step verification. Please log in with your password.',
    'invalid_credentials' => [
        'email' => 'The email or password is incorrect.',
        'phone' => 'The phone number or password is incorrect.',
    ],
    'otp_invalid' => 'The verification code is incorrect.',
    'otp_expired' => 'The verification code has expired. Please request a new one.',
    'otp_too_many_attempts' => 'Too many incorrect attempts. Please request a new code.',
    'otp_resend_wait' => 'Please wait :seconds seconds before requesting a new code.',
    'reset_token_invalid' => 'This password reset link is invalid or has expired.',
    'too_many_requests' => 'Too many attempts. Please try again in a minute.',

    'otp_subject' => [
        'quick' => 'Your Mutamakin code',
        'two_factor' => 'Your Mutamakin verification code',
        'signup' => 'Verify your Mutamakin account',
        'login' => 'Your Mutamakin login code',
        'reset' => 'Reset your Mutamakin password',
    ],
    'otp_line' => 'Enter this code on Mutamakin to continue:',
    'otp_expiry' => 'This code expires in :minutes minutes. If you did not request it, you can ignore this message.',
    'otp_whatsapp' => 'Your Mutamakin verification code is :code. It expires in :minutes minutes.',
    'suspended' => 'Account suspended. Contact support.',
    'admins_only' => 'Administrators only.',
];
