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

// Every minute — live DHCP mirror. This is deliberately read-only: it stores
// current lease state and exact customer matches locally but never changes a
// MikroTik lease, queue, DHCP server, VLAN, firewall, or unknown client.
Schedule::command('dhcp:sync --read-only')
    ->everyMinute()
    ->timezone($tz)
    ->withoutOverlapping()
    ->runInBackground();

// Every two minutes — apply only a tiny, exact-match static-lease batch after
// the read-only mirror has established current DHCP state. This deliberately
// avoids a long dashboard request or an all-at-once RouterOS write run.
$dhcpStaticBatchSize = min(10, max(1, (int) env('DHCP_STATIC_ENFORCEMENT_BATCH_SIZE', 2)));
Schedule::command('dhcp:enforce-static --limit=' . $dhcpStaticBatchSize)
    ->everyTwoMinutes()
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

// Every 10 minutes — recover only audited email/SMS records that were queued
// or failed and still have their single retry available. Null legacy audit
// records are intentionally excluded to prevent a historical bulk send.
Schedule::command('automation:recover-billing-deliveries')
    ->everyTenMinutes()
    ->timezone($tz)
    ->withoutOverlapping()
    ->runInBackground();

// Every minute — move durable mass-advisory recipient rows into Redis. The
// database is the outbox, so an HTTP/Redis interruption cannot lose a campaign.
Schedule::command('sms:dispatch-advisory-outbox --limit=250')
    ->everyMinute()
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
