import Heading from '@/components/heading';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PaymentStatusBadge } from '@/features/purchasing/status-badge';
import { type Sale } from '@/features/sales/types';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatMoney } from '@/lib/format';
import { type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface SalesIndexProps {
    sales: Paginated<Sale>;
    filters: { q: string; sale_type: string; status: string; party_id: number | null; from: string; to: string };
}

const selectClass = 'border-input bg-background h-9 rounded-md border px-3 text-sm';

export default function SalesIndex({ sales, filters }: SalesIndexProps) {
    const can = useCan();
    const [form, setForm] = useState({ q: filters.q, sale_type: filters.sale_type, status: filters.status, from: filters.from, to: filters.to });

    const apply: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(
            route('sales.index'),
            Object.fromEntries(Object.entries({ ...form, party_id: filters.party_id ?? '' }).filter(([, v]) => v !== '' && v !== null)),
            { preserveState: true, replace: true },
        );
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Sales', href: route('sales.index') }]}>
            <Head title="Sales" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <Heading title="Sales" description="Completed POS invoices." />
                    {can('sales.create') && (
                        <Button asChild>
                            <Link href={route('pos.index')}>Open POS</Link>
                        </Button>
                    )}
                </div>

                <form onSubmit={apply} className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                    <Input
                        placeholder="Invoice no."
                        value={form.q}
                        onChange={(e) => setForm({ ...form, q: e.target.value })}
                        className="sm:max-w-48"
                    />
                    <select
                        className={selectClass}
                        value={form.sale_type}
                        onChange={(e) => setForm({ ...form, sale_type: e.target.value })}
                        aria-label="Sale type"
                    >
                        <option value="">Retail & wholesale</option>
                        <option value="RETAIL">Retail</option>
                        <option value="WHOLESALE">Wholesale</option>
                    </select>
                    <select
                        className={selectClass}
                        value={form.status}
                        onChange={(e) => setForm({ ...form, status: e.target.value })}
                        aria-label="Payment status"
                    >
                        <option value="">All statuses</option>
                        <option value="PAID">Paid</option>
                        <option value="PARTIAL">Partial</option>
                        <option value="DUE">Due</option>
                    </select>
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
                                <th className="px-4 py-3 font-medium">Invoice</th>
                                <th className="px-4 py-3 font-medium">Customer</th>
                                <th className="px-4 py-3 font-medium">Type</th>
                                <th className="px-4 py-3 text-right font-medium">Total</th>
                                <th className="px-4 py-3 text-right font-medium">Due</th>
                                <th className="px-4 py-3 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {sales.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground px-4 py-8 text-center">
                                        No sales found.
                                    </td>
                                </tr>
                            )}
                            {sales.data.map((sale) => (
                                <tr key={sale.id} className="hover:bg-muted/30 border-t">
                                    <td className="px-4 py-3">
                                        <Link href={route('sales.show', sale.id)} className="font-mono font-medium hover:underline">
                                            {sale.invoice_no}
                                        </Link>
                                        <div className="text-muted-foreground text-xs">
                                            {formatDateTime(sale.sold_at)}
                                            {sale.created_by && ` · ${sale.created_by}`}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">{sale.party?.name ?? 'Walk-in'}</td>
                                    <td className="px-4 py-3">{sale.sale_type_label}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">{formatMoney(sale.total)}</td>
                                    <td className="px-4 py-3 text-right font-medium tabular-nums">{formatMoney(sale.due_amount)}</td>
                                    <td className="px-4 py-3">
                                        <PaymentStatusBadge status={sale.payment_status} label={sale.payment_status_label} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination meta={sales.meta} />
            </div>
        </AppLayout>
    );
}
