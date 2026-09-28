import Heading from '@/components/heading';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { type Payment, type SelectOption } from '@/features/purchasing/types';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatMoney, toCents } from '@/lib/format';
import { type Option, type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface PaymentsIndexProps {
    payments: Paginated<Payment>;
    filters: { party_id: number | null; purpose: string };
    suppliers: Option[];
    purposes: SelectOption[];
}

const selectClass = 'border-input bg-background h-9 rounded-md border px-3 text-sm';

export default function SupplierPaymentsIndex({ payments, filters, suppliers, purposes }: PaymentsIndexProps) {
    const can = useCan();
    const [form, setForm] = useState({ party_id: filters.party_id ? String(filters.party_id) : '', purpose: filters.purpose });

    const apply: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('supplier-payments.index'), Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '')), {
            preserveState: true,
            replace: true,
        });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Supplier payments', href: route('supplier-payments.index') }]}>
            <Head title="Supplier payments" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <Heading title="Supplier payments" description="Payments, advances and refunds with suppliers." />
                    {can('payments.create') && (
                        <div className="flex gap-2">
                            <Button asChild>
                                <Link href={route('supplier-payments.create')}>Pay supplier</Link>
                            </Button>
                            <Button variant="secondary" asChild>
                                <Link href={route('supplier-payments.create', { mode: 'advance' })}>Pay advance</Link>
                            </Button>
                        </div>
                    )}
                </div>

                <form onSubmit={apply} className="flex flex-col gap-2 sm:flex-row">
                    <select
                        className={selectClass}
                        value={form.party_id}
                        onChange={(e) => setForm({ ...form, party_id: e.target.value })}
                        aria-label="Supplier"
                    >
                        <option value="">All suppliers</option>
                        {suppliers.map((supplier) => (
                            <option key={supplier.id} value={supplier.id}>
                                {supplier.name}
                            </option>
                        ))}
                    </select>
                    <select
                        className={selectClass}
                        value={form.purpose}
                        onChange={(e) => setForm({ ...form, purpose: e.target.value })}
                        aria-label="Type"
                    >
                        <option value="">All types</option>
                        {purposes.map((purpose) => (
                            <option key={purpose.value} value={purpose.value}>
                                {purpose.label}
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
                                <th className="px-4 py-3 font-medium">Payment</th>
                                <th className="px-4 py-3 font-medium">Supplier</th>
                                <th className="px-4 py-3 font-medium">Type</th>
                                <th className="px-4 py-3 text-right font-medium">Amount</th>
                                <th className="px-4 py-3 text-right font-medium">Unapplied</th>
                            </tr>
                        </thead>
                        <tbody>
                            {payments.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="text-muted-foreground px-4 py-8 text-center">
                                        No payments yet.
                                    </td>
                                </tr>
                            )}
                            {payments.data.map((payment) => (
                                <tr key={payment.id} className="border-t">
                                    <td className="px-4 py-3">
                                        <Link
                                            href={route('supplier-payments.show', payment.id)}
                                            className="font-mono font-medium whitespace-nowrap hover:underline"
                                        >
                                            {payment.payment_no}
                                        </Link>
                                        <div className="text-muted-foreground text-xs">
                                            {formatDateTime(payment.paid_at)} · {payment.method_label}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">{payment.party?.name}</td>
                                    <td className="px-4 py-3">
                                        <Badge variant={payment.direction === 'IN' ? 'secondary' : 'outline'}>{payment.purpose_label}</Badge>
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {payment.direction === 'IN' ? '+' : '−'}
                                        {formatMoney(payment.amount)}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {payment.direction === 'OUT' && toCents(payment.unallocated_amount) > 0
                                            ? formatMoney(payment.unallocated_amount)
                                            : '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination meta={payments.meta} />
            </div>
        </AppLayout>
    );
}
