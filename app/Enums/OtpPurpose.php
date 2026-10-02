<?php

namespace App\Enums;

enum OtpPurpose: string
{
    case Signup = 'signup';
    case Login = 'login';
    case Reset = 'reset';
}
