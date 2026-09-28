import Heading from '@/components/heading';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PaymentStatusBadge } from '@/features/purchasing/status-badge';
import { type ServiceInvoice } from '@/features/service/types';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatMoney } from '@/lib/format';
import { type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface InvoicesIndexProps {
    invoices: Paginated<ServiceInvoice>;
    filters: { q: string; status: string };
}

const selectClass = 'border-input bg-background h-9 rounded-md border px-3 text-sm';

export default function ServiceInvoicesIndex({ invoices, filters }: InvoicesIndexProps) {
    const [form, setForm] = useState(filters);

    const apply: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('service-invoices.index'), Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '')), {
            preserveState: true,
            replace: true,
        });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Service invoices', href: route('service-invoices.index') }]}>
            <Head title="Service invoices" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading title="Service invoices" description="Repair bills: parts (product revenue) and service charges (service revenue)." />

                <form onSubmit={apply} className="flex flex-col gap-2 sm:flex-row">
                    <Input
                        placeholder="Invoice no."
                        value={form.q}
                        onChange={(e) => setForm({ ...form, q: e.target.value })}
                        className="sm:max-w-48"
                    />
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
                                <th className="px-4 py-3 text-right font-medium">Parts</th>
                                <th className="px-4 py-3 text-right font-medium">Service</th>
                                <th className="px-4 py-3 text-right font-medium">Total</th>
                                <th className="px-4 py-3 text-right font-medium">Due</th>
                                <th className="px-4 py-3 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {invoices.data.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="text-muted-foreground px-4 py-8 text-center">
                                        No service invoices found.
                                    </td>
                                </tr>
                            )}
                            {invoices.data.map((invoice) => (
                                <tr key={invoice.id} className="hover:bg-muted/30 border-t">
                                    <td className="px-4 py-3">
                                        <Link href={route('service-invoices.show', invoice.id)} className="font-mono font-medium hover:underline">
                                            {invoice.invoice_no}
                                        </Link>
                                        <div className="text-muted-foreground text-xs">
                                            {formatDateTime(invoice.invoiced_at)} · {invoice.job?.job_no}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">{invoice.party?.name}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">{formatMoney(invoice.product_total)}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">{formatMoney(invoice.service_total)}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">{formatMoney(invoice.total)}</td>
                                    <td className="px-4 py-3 text-right font-medium tabular-nums">{formatMoney(invoice.due_amount)}</td>
                                    <td className="px-4 py-3">
                                        <PaymentStatusBadge status={invoice.payment_status} label={invoice.payment_status_label} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination meta={invoices.meta} />
            </div>
        </AppLayout>
    );
}
