<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditTrail;
use App\Domain\Backup\BackupInProgress;
use App\Domain\Backup\BackupManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Admin → Backups: manual backups (create, download, delete, clear) and the daily automatic backup.
 * Admin only (the "manage-backups" ability is never granted through permissions).
 */
class BackupController extends Controller
{
    public function __construct(
        private readonly BackupManager $backups,
        private readonly AuditTrail $audit,
    ) {}

    public function index(): Response
    {
        Gate::authorize('manage-backups');

        return Inertia::render('admin/backups', [
            'manual' => $this->backups->manualBackups(),
            'auto' => $this->backups->autoBackup(),
            'schedule' => [
                'enabled' => (bool) config('backup.auto.enabled'),
                'time' => config('backup.auto.time'),
                'path' => $this->backups->autoPath(),
            ],
        ]);
    }

    public function store(): RedirectResponse
    {
        Gate::authorize('manage-backups');

        try {
            $backup = $this->backups->createManual();
        } catch (BackupInProgress $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('Manual database backup failed.', ['exception' => $e]);

            return back()->with('error', 'The backup failed. See the application log for details.');
        }

        $this->audit->record('backup.created', new: ['file' => $backup['name'], 'size' => $backup['size']], description: $backup['name']);

        return back()->with('success', "Backup {$backup['name']} created.");
    }

    public function download(string $name): BinaryFileResponse
    {
        Gate::authorize('manage-backups');

        $path = $this->manualPath($name);
        abort_unless(is_file($path), 404);

        $this->audit->record('backup.downloaded', description: $name);

        return response()->download($path, $name, ['Content-Type' => 'application/gzip']);
    }

    public function downloadAuto(): BinaryFileResponse
    {
        Gate::authorize('manage-backups');

        $auto = $this->backups->autoBackup();
        abort_unless($auto !== null, 404);

        // Dated name so downloads from different days do not overwrite each other on the admin's PC.
        $name = 'auto-'.date('Ymd-His', (int) filemtime($auto['path'])).'.sql.gz';
        $this->audit->record('backup.downloaded', description: $name);

        return response()->download($auto['path'], $name, ['Content-Type' => 'application/gzip']);
    }

    public function destroy(string $name): RedirectResponse
    {
        Gate::authorize('manage-backups');

        abort_unless(is_file($this->manualPath($name)) && $this->backups->deleteManual($name), 404);
        $this->audit->record('backup.deleted', description: $name);

        return back()->with('success', "Backup {$name} deleted.");
    }

    /**
     * Only manual backup file names are accepted (checked after authorization); anything else is 404.
     */
    private function manualPath(string $name): string
    {
        try {
            return $this->backups->manualPath($name);
        } catch (InvalidArgumentException) {
            abort(404);
        }
    }

    public function clear(): RedirectResponse
    {
        Gate::authorize('manage-backups');

        $deleted = $this->backups->clearManual();
        $this->audit->record('backup.cleared', new: ['deleted' => $deleted], description: "{$deleted} manual backup(s)");

        return back()->with('success', "{$deleted} manual backup(s) deleted.");
    }
}
