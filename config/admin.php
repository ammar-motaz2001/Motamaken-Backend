<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Staff permissions
    |--------------------------------------------------------------------------
    |
    | What a staff role can be given. An admin without a role, or with "*", has all of them. The admin routes check these
    | with the `admin.can:<permission>` middleware, and the panel hides what the viewer cannot use.
    |
    */

    'permissions' => [
        'overview',
        'users.view',
        'users.manage',
        'users.wallet',
        'kyc',
        'reports',
        'marketplace',
        'disputes',
        'finance.view',
        'finance.manage',
        'rewards',
        'content',
        'support',
        'notifications',
        'settings',
        'staff',
        'exports',
    ],

    /*
    |--------------------------------------------------------------------------
    | Disputes
    |--------------------------------------------------------------------------
    */

    // A side may appeal a settled dispute within this many days.
    'appeal_days' => 7,

];
