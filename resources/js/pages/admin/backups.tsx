import { ConfirmDialog } from '@/components/confirm-dialog';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/format';
import { Head, router } from '@inertiajs/react';
import { DatabaseBackup, Download, LoaderCircle, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface BackupFile {
    name: string;
    size: number;
    created_at: string;
}

interface BackupsProps {
    manual: BackupFile[];
    auto: (BackupFile & { path: string }) | null;
    schedule: { enabled: boolean; time: string; path: string };
}

const formatSize = (bytes: number) => {
    const units = ['B', 'KB', 'MB', 'GB'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
};

/**
 * Admin → Backups: on-demand database backups to download, and the single daily automatic backup.
 */
export default function Backups({ manual, auto, schedule }: BackupsProps) {
    const [creating, setCreating] = useState(false);
    const [deleting, setDeleting] = useState<string | null>(null);
    const [clearing, setClearing] = useState(false);
    const [processing, setProcessing] = useState(false);

    const create = () =>
        router.post(route('admin.backups.store'), {}, { preserveScroll: true, onStart: () => setCreating(true), onFinish: () => setCreating(false) });

    const remove = (name: string) =>
        router.delete(route('admin.backups.destroy', name), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setDeleting(null);
            },
        });

    const clear = () =>
        router.delete(route('admin.backups.clear'), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setClearing(false);
            },
        });

    return (
        <AppLayout breadcrumbs={[{ title: 'Backups', href: route('admin.backups.index') }]}>
            <Head title="Backups" />
            <div className="max-w-4xl space-y-8 p-4 md:p-6">
                <Heading
                    title="Database backups"
                    description="Compressed SQL copies of the whole database. Only Admin can create or download them."
                />

                <section className="space-y-3 rounded-lg border p-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 className="font-medium">Automatic daily backup</h3>
                            <p className="text-muted-foreground text-sm">
                                {schedule.enabled
                                    ? `Runs every day at ${schedule.time}. The same file is replaced each time, so it never fills the disk.`
                                    : 'Disabled (BACKUP_AUTO_ENABLED=false).'}
                            </p>
                        </div>
                        {auto && (
                            <Button asChild variant="outline">
                                <a href={route('admin.backups.auto.download')}>
                                    <Download className="size-4" />
                                    Download
                                </a>
                            </Button>
                        )}
                    </div>
                    <dl className="grid gap-2 text-sm sm:grid-cols-[160px_minmax(0,1fr)]">
                        <dt className="text-muted-foreground">Location</dt>
                        <dd className="font-mono text-xs break-all">{schedule.path}</dd>
                        <dt className="text-muted-foreground">Last backup</dt>
                        <dd>{auto ? `${formatDateTime(auto.created_at)} · ${formatSize(auto.size)}` : 'Not created yet'}</dd>
                    </dl>
                    {!auto && schedule.enabled && (
                        <p className="text-muted-foreground text-xs">
                            The scheduler must be running: the “scheduler” container in Docker, or <code>php artisan schedule:work</code>.
                        </p>
                    )}
                </section>

                <section className="space-y-3">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 className="font-medium">Manual backups</h3>
                            <p className="text-muted-foreground text-sm">Kept until you delete them. Download a copy to keep it off this server.</p>
                        </div>
                        <div className="flex gap-2">
                            <Button variant="outline" onClick={() => setClearing(true)} disabled={manual.length === 0 || processing}>
                                <Trash2 className="size-4" />
                                Clear
                            </Button>
                            <Button onClick={create} disabled={creating}>
                                {creating ? <LoaderCircle className="size-4 animate-spin" /> : <DatabaseBackup className="size-4" />}
                                {creating ? 'Backing up…' : 'Back up now'}
                            </Button>
                        </div>
                    </div>

                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-4 py-3 font-medium">File</th>
                                    <th className="px-4 py-3 font-medium">Created</th>
                                    <th className="px-4 py-3 text-right font-medium">Size</th>
                                    <th className="px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody>
                                {manual.length === 0 && (
                                    <tr>
                                        <td colSpan={4} className="text-muted-foreground px-4 py-6 text-center">
                                            No manual backups.
                                        </td>
                                    </tr>
                                )}
                                {manual.map((backup) => (
                                    <tr key={backup.name} className="border-t">
                                        <td className="px-4 py-3 font-mono text-xs">{backup.name}</td>
                                        <td className="px-4 py-3 whitespace-nowrap">{formatDateTime(backup.created_at)}</td>
                                        <td className="px-4 py-3 text-right tabular-nums">{formatSize(backup.size)}</td>
                                        <td className="px-4 py-3">
                                            <div className="flex justify-end gap-1">
                                                <Button asChild variant="ghost" size="sm">
                                                    <a href={route('admin.backups.download', backup.name)}>
                                                        <Download className="size-4" />
                                                        Download
                                                    </a>
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    onClick={() => setDeleting(backup.name)}
                                                    aria-label={`Delete ${backup.name}`}
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <p className="text-muted-foreground text-xs">
                    Restore (from a terminal): <code>gunzip -c backup.sql.gz | mysql -u USER -p DATABASE</code>. Restoring replaces all current data.
                </p>
            </div>

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title="Delete backup?"
                description={`${deleting ?? ''} will be permanently deleted from the server.`}
                confirmLabel="Delete"
                destructive
                processing={processing}
                onConfirm={() => deleting && remove(deleting)}
            />
            <ConfirmDialog
                open={clearing}
                onOpenChange={setClearing}
                title="Clear all manual backups?"
                description={`All ${manual.length} manual backup(s) will be permanently deleted. The automatic daily backup is kept.`}
                confirmLabel="Clear all"
                destructive
                processing={processing}
                onConfirm={clear}
            />
        </AppLayout>
    );
}
