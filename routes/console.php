<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Team invitations run out after 7 days.
Schedule::command('teams:expire-invitations')->hourly();

// Subscriptions renew (or lapse) once a day.
Schedule::command('subscriptions:renew')->dailyAt('03:00');

// Prize draws pick their winners when their time comes.
Schedule::command('draws:run')->everyFiveMinutes();

// Campaigns past their end date stop and give back what is left.
Schedule::command('campaigns:close-ended')->everyFifteenMinutes();
