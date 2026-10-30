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

// DoorDash's news that its webhook didn't bring (always, where DoorDash can't reach the API).
Schedule::command('orders:sync-couriers')->everyMinute()->withoutOverlapping();
