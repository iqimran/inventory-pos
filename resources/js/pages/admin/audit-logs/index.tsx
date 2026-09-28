import Heading from '@/components/heading';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/format';
import { type Option, type Paginator } from '@/types';
import { Head, router } from '@inertiajs/react';
import { FormEventHandler, Fragment, useState } from 'react';

type Values = Record<string, unknown> | null;

interface AuditEntry {
    id: number;
    event: string;
    subject: { type: string; id: number } | null;
    description: string | null;
    old_values: Values;
    new_values: Values;
    user: string | null;
    ip_address: string | null;
    created_at: string | null;
}

interface AuditLogProps {
    logs: Paginator<AuditEntry>;
    filters: { event: string; user_id: string | number; q: string; from: string; to: string };
    events: string[];
    users: Option[];
}

const selectClass = 'border-input bg-background h-9 rounded-md border px-3 text-sm';
const show = (value: unknown) =>
    value === null || value === undefined || value === ''
        ? '—'
        : Array.isArray(value) || typeof value === 'object'
          ? JSON.stringify(value)
          : String(value);

function Changes({ entry }: { entry: AuditEntry }) {
    const keys = Array.from(new Set([...Object.keys(entry.old_values ?? {}), ...Object.keys(entry.new_values ?? {})]));

    if (keys.length === 0) return null;

    return (
        <dl className="mt-1 grid grid-cols-[minmax(90px,auto)_1fr] gap-x-3 gap-y-0.5 text-xs">
            {keys.map((key) => (
                <Fragment key={key}>
                    <dt className="text-muted-foreground">{key}</dt>
                    <dd className="font-mono break-all">
                        {entry.old_values && key in entry.old_values && (
                            <span className="text-destructive line-through">{show(entry.old_values[key])}</span>
                        )}
                        {entry.old_values && key in entry.old_values && entry.new_values && key in entry.new_values && ' → '}
                        {entry.new_values && key in entry.new_values && <span>{show(entry.new_values[key])}</span>}
                    </dd>
                </Fragment>
            ))}
        </dl>
    );
}

/**
 * Who changed what, and when: master data and prices, users and permissions, settings, stock and
 * ledger adjustments, price overrides and sign-ins. Transactions have their own ledgers.
 */
export default function AuditLogIndex({ logs, filters, events, users }: AuditLogProps) {
    const [form, setForm] = useState({ ...filters, user_id: String(filters.user_id ?? '') });
    const groups = Array.from(new Set(events.map((event) => event.split('.')[0])));

    const apply: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('admin.audit-logs.index'), Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '')), {
            preserveState: true,
            replace: true,
        });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Audit log', href: route('admin.audit-logs.index') }]}>
            <Head title="Audit log" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading
                    title="Audit log"
                    description="Price and master-data changes, users and permissions, settings, stock and ledger adjustments, price overrides and sign-ins. Sales, purchases, payments and stock movements keep their own permanent records."
                />

                <form onSubmit={apply} className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                    <select
                        className={selectClass}
                        value={form.event}
                        onChange={(e) => setForm({ ...form, event: e.target.value })}
                        aria-label="Event"
                    >
                        <option value="">All events</option>
                        {groups.map((group) => (
                            <optgroup key={group} label={group}>
                                <option value={group}>All {group} events</option>
                                {events
                                    .filter((event) => event.startsWith(`${group}.`))
                                    .map((event) => (
                                        <option key={event} value={event}>
                                            {event}
                                        </option>
                                    ))}
                            </optgroup>
                        ))}
                    </select>
                    <select
                        className={selectClass}
                        value={form.user_id}
                        onChange={(e) => setForm({ ...form, user_id: e.target.value })}
                        aria-label="User"
                    >
                        <option value="">All users</option>
                        {users.map((user) => (
                            <option key={user.id} value={user.id}>
                                {user.name}
                            </option>
                        ))}
                    </select>
                    <Input
                        value={form.q}
                        onChange={(e) => setForm({ ...form, q: e.target.value })}
                        placeholder="Search description"
                        className="sm:w-52"
                    />
                    <Input
                        type="date"
                        value={form.from}
                        onChange={(e) => setForm({ ...form, from: e.target.value })}
                        className="sm:w-40"
                        aria-label="From"
                    />
                    <Input
                        type="date"
                        value={form.to}
                        onChange={(e) => setForm({ ...form, to: e.target.value })}
                        className="sm:w-40"
                        aria-label="To"
                    />
                    <Button type="submit" variant="secondary">
                        Filter
                    </Button>
                </form>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-4 py-3 font-medium">When</th>
                                <th className="px-4 py-3 font-medium">User</th>
                                <th className="px-4 py-3 font-medium">Event</th>
                                <th className="px-4 py-3 font-medium">Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            {logs.data.length === 0 && (
                                <tr>
                                    <td colSpan={4} className="text-muted-foreground px-4 py-8 text-center">
                                        No audit entries match.
                                    </td>
                                </tr>
                            )}
                            {logs.data.map((entry) => (
                                <tr key={entry.id} className="border-t align-top">
                                    <td className="px-4 py-3 whitespace-nowrap">
                                        {formatDateTime(entry.created_at)}
                                        {entry.ip_address && <div className="text-muted-foreground text-xs">{entry.ip_address}</div>}
                                    </td>
                                    <td className="px-4 py-3">{entry.user ?? <span className="text-muted-foreground">System</span>}</td>
                                    <td className="px-4 py-3 font-mono text-xs">{entry.event}</td>
                                    <td className="px-4 py-3">
                                        {entry.description && <div>{entry.description}</div>}
                                        {entry.subject && (
                                            <div className="text-muted-foreground text-xs">
                                                {entry.subject.type} #{entry.subject.id}
                                            </div>
                                        )}
                                        <Changes entry={entry} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination meta={logs} />
            </div>
        </AppLayout>
    );
}
