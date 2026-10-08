<?php

use Illuminate\Support\Facades\Schedule;

/*
| Scheduler (cron: `* * * * * php artisan schedule:run`). Times are Indian Standard Time.
*/

Schedule::command('procurement:expire')->dailyAt('00:15')->timezone('Asia/Kolkata')->withoutOverlapping();
Schedule::command('scales:certificate-reminders')->dailyAt('08:00')->timezone('Asia/Kolkata')->withoutOverlapping();
Schedule::command('audit:verify')->dailyAt('02:00')->timezone('Asia/Kolkata')->withoutOverlapping();
Schedule::command('stock:verify')->dailyAt('02:30')->timezone('Asia/Kolkata')->withoutOverlapping();
Schedule::command('books:verify')->dailyAt('02:45')->timezone('Asia/Kolkata')->withoutOverlapping();
