<?php

// Only the rules used by the API; anything missing falls back to English.
return [
    'no_markup' => 'لا يُسمح بكتابة أكواد أو وسوم HTML هنا.',
    'boolean' => 'يجب أن يكون حقل :attribute صحيحاً أو خاطئاً.',
    'digits' => 'يجب أن يتكون :attribute من :digits أرقام.',
    'email' => 'يجب أن يكون :attribute بريداً إلكترونياً صحيحاً.',
    'enum' => 'قيمة :attribute المختارة غير صالحة.',
    'max' => [
        'string' => 'يجب ألا يزيد :attribute عن :max حرفاً.',
    ],
    'min' => [
        'string' => 'يجب ألا يقل :attribute عن :min أحرف.',
    ],
    'password' => [
        'letters' => 'يجب أن تحتوي :attribute على حرف واحد على الأقل.',
        'mixed' => 'يجب أن تحتوي :attribute على حرف كبير وحرف صغير على الأقل.',
        'numbers' => 'يجب أن تحتوي :attribute على رقم واحد على الأقل.',
        'symbols' => 'يجب أن تحتوي :attribute على رمز واحد على الأقل.',
        'uncompromised' => ':attribute المدخلة ظهرت في تسريب بيانات. يرجى اختيار :attribute مختلفة.',
    ],
    'phone' => 'يجب أن يكون :attribute رقم هاتف صحيحاً مع رمز الدولة.',
    'regex' => 'صيغة :attribute غير صالحة.',
    'required' => 'حقل :attribute مطلوب.',
    'required_if' => 'حقل :attribute مطلوب.',
    'string' => 'يجب أن يكون :attribute نصاً.',

    'attributes' => [
        'method' => 'طريقة التسجيل',
        'firstName' => 'الاسم الأول',
        'lastName' => 'اسم العائلة',
        'username' => 'اسم المستخدم',
        'email' => 'البريد الإلكتروني',
        'phone' => 'رقم الهاتف',
        'identifier' => 'البريد الإلكتروني أو رقم الهاتف',
        'target' => 'البريد الإلكتروني أو رقم الهاتف',
        'password' => 'كلمة المرور',
        'code' => 'رمز التحقق',
        'purpose' => 'الغرض',
        'token' => 'الرمز',
        'subscribe' => 'الاشتراك',
    ],

    'custom' => [
        'recaptchaToken' => ['required' => 'تعذّر التحقق من فحص الأمان (reCAPTCHA). أعد تحميل الصفحة، وأوقف مانع الإعلانات أو الـVPN لهذا الموقع إن وجد، ثم حاول مرة أخرى.'],
    ],
];
