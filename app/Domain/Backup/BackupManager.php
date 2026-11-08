<?php

namespace App\Domain\Backup;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Manual backups (one file per run, kept until deleted) and the single daily automatic backup
 * (one file, overwritten each day so it never grows).
 */
class BackupManager
{
    /** Manual backup file names; anything else is refused (no path traversal). */
    private const MANUAL_NAME = '/^manual-\d{8}-\d{6}\.sql\.gz$/';

    private const LOCK = 'database-backup';

    public function __construct(private readonly DatabaseDumper $dumper) {}

    /**
     * @return array{name: string, size: int, created_at: string}
     */
    public function createManual(): array
    {
        $name = 'manual-'.now()->format('Ymd-His').'.sql.gz';

        $this->exclusively(fn () => $this->dumper->dump($this->manualDirectory().'/'.$name));

        return $this->describe($this->manualDirectory().'/'.$name);
    }

    /**
     * Writes the automatic backup, replacing yesterday's file only once today's is complete.
     *
     * @return array{name: string, size: int, created_at: string}
     */
    public function runAuto(): array
    {
        $this->exclusively(fn () => $this->dumper->dump($this->autoPath()));

        return $this->describe($this->autoPath());
    }

    /**
     * @return list<array{name: string, size: int, created_at: string}> newest first
     */
    public function manualBackups(): array
    {
        $files = glob($this->manualDirectory().'/manual-*.sql.gz') ?: [];
        $files = array_values(array_filter($files, fn (string $file) => preg_match(self::MANUAL_NAME, basename($file)) === 1));
        rsort($files);

        return array_map(fn (string $file) => $this->describe($file), $files);
    }

    /**
     * @return array{name: string, size: int, created_at: string, path: string}|null
     */
    public function autoBackup(): ?array
    {
        return is_file($this->autoPath()) ? [...$this->describe($this->autoPath()), 'path' => $this->autoPath()] : null;
    }

    public function manualPath(string $name): string
    {
        if (preg_match(self::MANUAL_NAME, $name) !== 1) {
            throw new InvalidArgumentException('Invalid backup name.');
        }

        return $this->manualDirectory().'/'.$name;
    }

    public function deleteManual(string $name): bool
    {
        $path = $this->manualPath($name);

        return is_file($path) && unlink($path);
    }

    /**
     * Deletes every manual backup; the automatic backup is kept.
     */
    public function clearManual(): int
    {
        return count(array_filter($this->manualBackups(), fn (array $backup) => $this->deleteManual($backup['name'])));
    }

    public function autoPath(): string
    {
        return (string) config('backup.auto.path');
    }

    private function manualDirectory(): string
    {
        return rtrim((string) config('backup.manual.directory'), '/');
    }

    /**
     * One backup at a time (manual and automatic share the lock).
     */
    private function exclusively(callable $callback): void
    {
        $lock = Cache::lock(self::LOCK, 3600);

        if (! $lock->get()) {
            throw new BackupInProgress('Another backup is running. Try again in a minute.');
        }

        try {
            @set_time_limit(0);
            $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{name: string, size: int, created_at: string}
     */
    private function describe(string $file): array
    {
        clearstatcache(true, $file);

        return [
            'name' => basename($file),
            'size' => (int) filesize($file),
            'created_at' => CarbonImmutable::createFromTimestamp((int) filemtime($file), config('app.timezone'))->toIso8601String(),
        ];
    }
}
