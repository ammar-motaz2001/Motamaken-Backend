<?php

namespace App\Enums;

enum OtpPurpose: string
{
    case Signup = 'signup';
    case Login = 'login';
    case Reset = 'reset';
    case TwoFactor = 'two_factor';

    /** Passwordless sign-in or sign-up at the moment a guest commits to an action (POST /auth/quick/*). */
    case Quick = 'quick';
}
