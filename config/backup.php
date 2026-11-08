<?php

/*
 * Database backups (Admin → Backups). Files are gzip-compressed SQL that MySQL can import:
 *   gunzip -c backup.sql.gz | mysql -u <user> -p <database>
 */
return [

    // Daily automatic backup, written by the scheduler (php artisan schedule:work).
    'auto' => [
        'enabled' => (bool) env('BACKUP_AUTO_ENABLED', true),
        // 24-hour HH:MM in the application time zone.
        'time' => env('BACKUP_AUTO_TIME', '01:30'),
        // One file, overwritten every day so the disk never fills up.
        'path' => env('BACKUP_AUTO_PATH', storage_path('app/private/backups/auto/database-auto.sql.gz')),
    ],

    // Backups taken on demand from the Backups page (kept until deleted or cleared).
    'manual' => [
        'directory' => env('BACKUP_MANUAL_DIRECTORY', storage_path('app/private/backups/manual')),
    ],

];
