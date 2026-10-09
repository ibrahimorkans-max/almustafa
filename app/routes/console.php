<?php

use App\Domain\Ops\Heartbeat;
use App\Domain\Ops\OpsAlerts;
use App\Jobs\QueueHeartbeat;
use Illuminate\Support\Facades\Schedule;

/*
| Scheduler (cron: `* * * * * php artisan schedule:run`). Times are Indian Standard Time.
*/

Schedule::command('procurement:expire')->dailyAt('00:15')->timezone('Asia/Kolkata')->withoutOverlapping();
Schedule::command('scales:certificate-reminders')->dailyAt('08:00')->timezone('Asia/Kolkata')->withoutOverlapping();
Schedule::command('audit:verify')->dailyAt('02:00')->timezone('Asia/Kolkata')->withoutOverlapping();
Schedule::command('stock:verify')->dailyAt('02:30')->timezone('Asia/Kolkata')->withoutOverlapping();
Schedule::command('books:verify')->dailyAt('02:45')->timezone('Asia/Kolkata')->withoutOverlapping();
Schedule::command('archive:run')->monthlyOn(1, '03:30')->timezone('Asia/Kolkata')->withoutOverlapping();
Schedule::command('anomalies:scan')->dailyAt('03:00')->timezone('Asia/Kolkata')->withoutOverlapping();

// Heartbeats: the scheduler marks itself every minute and checks the queue worker every 5 minutes.
Schedule::call(fn () => Heartbeat::beat(Heartbeat::SCHEDULER))->everyMinute()->name('heartbeat:scheduler');
Schedule::job(new QueueHeartbeat(now()->toIso8601String()))->everyFiveMinutes()->name('heartbeat:queue');
Schedule::command('backup:run')->dailyAt('01:30')->timezone('Asia/Kolkata')->withoutOverlapping();
Schedule::command('backup:verify')->weeklyOn(0, '04:00')->timezone('Asia/Kolkata')->withoutOverlapping();
Schedule::call(fn () => app(OpsAlerts::class)->checkFailedLoginBurst())->everyFiveMinutes()->name('ops:failed-login-burst');
Schedule::command('hr:document-expiry')->dailyAt('07:30')->timezone('Asia/Kolkata')->withoutOverlapping();
