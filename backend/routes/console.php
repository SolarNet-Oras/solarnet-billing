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

// 00:05 — create the current month's invoice on every client's billing anniversary.
Schedule::command('automation:generate-recurring-invoices')
    ->dailyAt('00:05')
    ->timezone($tz)
    ->withoutOverlapping()
    ->runInBackground();

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

// 03:10 — off-peak DHCP mirror. This is deliberately read-only: it stores
// current lease state and exact customer matches locally but never changes a
// MikroTik lease, queue, DHCP server, VLAN, firewall, or unknown client.
Schedule::command('dhcp:sync --read-only')
    ->dailyAt('03:10')
    ->timezone($tz)
    ->withoutOverlapping()
    ->runInBackground();

// 08:00 — daily web-push reminders for unpaid invoices. Initial invoice
// emails are sent once when an invoice is created, not by this reminder job.
Schedule::command('automation:invoice-reminders')
    ->dailyAt('08:00')
    ->timezone($tz)
    ->withoutOverlapping()
    ->runInBackground();

// Every 5 minutes — reconcile billing and network suspension state.
Schedule::command('automation:auto-suspend')
    ->everyFiveMinutes()
    ->timezone($tz)
    ->withoutOverlapping()
    ->runInBackground();

// Every minute — settle paid PayMongo checkouts even if the browser return or
// payment webhook was missed.
Schedule::command('paymongo:reconcile-pending')
    ->everyMinute()
    ->timezone($tz)
    ->withoutOverlapping();

// Every minute — end controlled Safe QoS tests. A failed verification restores
// only the original SolarNet-managed customer Simple Queue snapshot.
Schedule::command('qos:complete-safe-tests')
    ->everyMinute()
    ->timezone($tz)
    ->withoutOverlapping()
    ->runInBackground();
