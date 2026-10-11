<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Points ("Points & gifts")
    |--------------------------------------------------------------------------
    |
    | What each action is worth. `once` actions are granted a single time per account. Points are spent on prize-draw
    | tickets.
    |
    */

    'points' => [
        'welcome' => ['amount' => 50, 'once' => true],
        'profile_completed' => ['amount' => 50, 'once' => true],
        'identity_verified' => ['amount' => 100, 'once' => true],
        'payment_method_added' => ['amount' => 20, 'once' => true],
        'project_completed' => ['amount' => 20, 'once' => false],
        'review_left' => ['amount' => 10, 'once' => false],
        'referral' => ['amount' => 25, 'once' => false],
        'promotion' => ['amount' => 200, 'once' => false],
    ],

    /*
    |--------------------------------------------------------------------------
    | Medals ("Medals system")
    |--------------------------------------------------------------------------
    |
    | Earned once each, when the metric reaches the target. Metrics are computed by App\Services\RewardsService.
    |
    */

    'medals' => [
        ['code' => 'verified', 'metric' => 'identity_verified', 'target' => 1],
        ['code' => 'payment_verified', 'metric' => 'payment_methods', 'target' => 1],
        ['code' => 'first_job', 'metric' => 'completed_jobs', 'target' => 1],
        ['code' => 'rising_star', 'metric' => 'completed_jobs', 'target' => 10],
        ['code' => 'top_rated', 'metric' => 'five_star_reviews', 'target' => 5],
        ['code' => 'trusted_client', 'metric' => 'completed_projects', 'target' => 5],
        ['code' => 'distinguished', 'metric' => 'promotions', 'target' => 1],
        ['code' => 'connector', 'metric' => 'referrals', 'target' => 5],
    ],

];
