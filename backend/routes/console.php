<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled jobs
|--------------------------------------------------------------------------
|
| Run by the `scheduler` container (`php artisan schedule:work`) locally, and by
| a cron entry calling `php artisan schedule:run` every minute in production.
|
*/

Schedule::command('orders:cancel-unpaid')->everyMinute()->withoutOverlapping();

Schedule::command('orders:auto-reject')->everyMinute()->withoutOverlapping();

// Square recommends renewing access tokens weekly; they last 30 days.
Schedule::command('square:refresh-tokens')->dailyAt('03:30')->withoutOverlapping();
