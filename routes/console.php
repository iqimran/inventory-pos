<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Nightly integrity checks (report only, never --fix): cached stock and party balances must equal
 * their immutable ledgers. A mismatch is logged as an error for the operator to investigate.
 * Run by the "scheduler" container (php artisan schedule:work).
 */
foreach (['inventory:reconcile', 'ledger:reconcile'] as $check) {
    Schedule::command($check)
        ->dailyAt('02:00')
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/scheduler.log'))
        ->onFailure(fn () => Log::error("Scheduled integrity check failed: {$check}. See storage/logs/scheduler.log."));
}

/*
 * Daily automatic database backup (Admin → Backups). Always the same file (config/backup.php),
 * replaced only after the new dump is complete, so it never fills the disk.
 */
if (config('backup.auto.enabled')) {
    Schedule::command('backup:database')
        ->dailyAt(config('backup.auto.time'))
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/scheduler.log'))
        ->onFailure(fn () => Log::error('Scheduled database backup failed. See storage/logs/scheduler.log.'));
}
