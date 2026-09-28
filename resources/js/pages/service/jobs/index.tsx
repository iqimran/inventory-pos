import Heading from '@/components/heading';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PaymentStatusBadge } from '@/features/purchasing/status-badge';
import { type SelectOption } from '@/features/purchasing/types';
import { ServiceStatusBadge } from '@/features/service/status-badge';
import { type ServiceJob, type Technician } from '@/features/service/types';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatMoney } from '@/lib/format';
import { type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface JobsIndexProps {
    jobs: Paginated<ServiceJob>;
    statuses: SelectOption[];
    technicians: Technician[];
    filters: { q: string; status: string; technician_id: string | number };
}

const selectClass = 'border-input bg-background h-9 rounded-md border px-3 text-sm';

export default function ServiceJobsIndex({ jobs, statuses, technicians, filters }: JobsIndexProps) {
    const can = useCan();
    const [form, setForm] = useState({ q: filters.q, status: filters.status, technician_id: String(filters.technician_id ?? '') });

    const apply: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('service-jobs.index'), Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '')), {
            preserveState: true,
            replace: true,
        });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Service jobs', href: route('service-jobs.index') }]}>
            <Head title="Service jobs" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <Heading title="Service jobs" description="Repairs from intake to delivery." />
                    {can('service.manage') && (
                        <Button asChild>
                            <Link href={route('service-jobs.create')}>New job</Link>
                        </Button>
                    )}
                </div>

                <form onSubmit={apply} className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                    <Input
                        placeholder="Job no., IMEI, serial or phone"
                        value={form.q}
                        onChange={(e) => setForm({ ...form, q: e.target.value })}
                        className="sm:max-w-64"
                    />
                    <select
                        className={selectClass}
                        value={form.status}
                        onChange={(e) => setForm({ ...form, status: e.target.value })}
                        aria-label="Status"
                    >
                        <option value="">All statuses</option>
                        {statuses.map((status) => (
                            <option key={status.value} value={status.value}>
                                {status.label}
                            </option>
                        ))}
                    </select>
                    <select
                        className={selectClass}
                        value={form.technician_id}
                        onChange={(e) => setForm({ ...form, technician_id: e.target.value })}
                        aria-label="Technician"
                    >
                        <option value="">All technicians</option>
                        {technicians.map((technician) => (
                            <option key={technician.id} value={technician.id}>
                                {technician.name}
                            </option>
                        ))}
                    </select>
                    <Button type="submit" variant="secondary">
                        Filter
                    </Button>
                </form>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-4 py-3 font-medium">Job</th>
                                <th className="px-4 py-3 font-medium">Customer</th>
                                <th className="px-4 py-3 font-medium">Device</th>
                                <th className="px-4 py-3 font-medium">Technician</th>
                                <th className="px-4 py-3 font-medium">Status</th>
                                <th className="px-4 py-3 text-right font-medium">Invoice</th>
                            </tr>
                        </thead>
                        <tbody>
                            {jobs.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground px-4 py-8 text-center">
                                        No service jobs found.
                                    </td>
                                </tr>
                            )}
                            {jobs.data.map((job) => (
                                <tr key={job.id} className="hover:bg-muted/30 border-t">
                                    <td className="px-4 py-3">
                                        <Link href={route('service-jobs.show', job.id)} className="font-mono font-medium hover:underline">
                                            {job.job_no}
                                        </Link>
                                        <div className="text-muted-foreground text-xs">{formatDateTime(job.received_at)}</div>
                                    </td>
                                    <td className="px-4 py-3">
                                        {job.party?.name}
                                        <div className="text-muted-foreground text-xs">{job.party?.phone}</div>
                                    </td>
                                    <td className="px-4 py-3">
                                        {job.device?.name}
                                        <div className="text-muted-foreground font-mono text-xs">{job.device?.imei1 ?? job.device?.serial_no}</div>
                                    </td>
                                    <td className="px-4 py-3">{job.technician ?? <span className="text-muted-foreground">Unassigned</span>}</td>
                                    <td className="px-4 py-3">
                                        <ServiceStatusBadge status={job.status} label={job.status_label} />
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        {job.invoice ? (
                                            <div className="flex flex-col items-end gap-1">
                                                <span className="tabular-nums">{formatMoney(job.invoice.total)}</span>
                                                <PaymentStatusBadge status={job.invoice.payment_status} label={job.invoice.payment_status_label} />
                                            </div>
                                        ) : (
                                            <span className="text-muted-foreground">—</span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination meta={jobs.meta} />
            </div>
        </AppLayout>
    );
}
