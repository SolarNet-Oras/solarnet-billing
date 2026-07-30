<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ------------------------------------------------------------------
// Scheduled automations (Asia/Manila).
// All commands short-circuit if Setting 'automation.enabled' is false,
// so a single toggle in the Settings UI pauses everything.
// ------------------------------------------------------------------
$tz = env('APP_TIMEZONE', 'Asia/Manila');

// 02:00 — flip past-due invoices to overdue
Schedule::command('automation:update-overdue')
    ->dailyAt('02:00')
    ->timezone($tz)
    ->withoutOverlapping()
    ->runInBackground();

// 02:15 — gzipped pg_dump backup + retention prune
Schedule::command('automation:db-backup')
    ->dailyAt('02:15')
    ->timezone($tz)
    ->withoutOverlapping()
    ->runInBackground();

// 08:00 — payment reminders (pre-due + overdue follow-ups)
Schedule::command('automation:invoice-reminders')
    ->dailyAt('08:00')
    ->timezone($tz)
    ->withoutOverlapping()
    ->runInBackground();

// 09:00 — auto-suspend customers past the grace period
Schedule::command('automation:auto-suspend')
    ->dailyAt('09:00')
    ->timezone($tz)
    ->withoutOverlapping()
    ->runInBackground();
