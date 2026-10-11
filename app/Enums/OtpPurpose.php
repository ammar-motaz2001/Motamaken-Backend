<?php

namespace App\Enums;

enum OtpPurpose: string
{
    case Signup = 'signup';
    case Login = 'login';
    case Reset = 'reset';
    case TwoFactor = 'two_factor';
}
