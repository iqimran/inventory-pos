<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditTrail;
use App\Domain\Backup\BackupManager;
use Illuminate\Console\Command;
use Illuminate\Support\Number;
use Throwable;

/**
 * Database backup from the command line or the scheduler:
 *   php artisan backup:database          the daily automatic backup (one file, overwritten)
 *   php artisan backup:database --manual a new manual backup (listed on Admin → Backups)
 */
class BackupDatabase extends Command
{
    protected $signature = 'backup:database {--manual : Keep a new manual backup instead of overwriting the automatic one}';

    protected $description = 'Back up the database (gzip-compressed SQL)';

    public function handle(BackupManager $backups, AuditTrail $audit): int
    {
        try {
            $backup = $this->option('manual') ? $backups->createManual() : $backups->runAuto();
        } catch (Throwable $e) {
            $this->error('Backup FAILED: '.$e->getMessage());

            return self::FAILURE;
        }

        $audit->record($this->option('manual') ? 'backup.created' : 'backup.auto', new: ['file' => $backup['name'], 'size' => $backup['size']], description: $backup['name']);
        $this->info("Backup OK: {$backup['name']} (".Number::fileSize($backup['size']).')');

        return self::SUCCESS;
    }
}
