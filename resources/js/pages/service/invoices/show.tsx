import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { PaymentStatusBadge } from '@/features/purchasing/status-badge';
import { type ServiceInvoice } from '@/features/service/types';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatMoney, toCents } from '@/lib/format';
import { Head, Link } from '@inertiajs/react';

/**
 * Combined service invoice: PRODUCT lines (parts) and SERVICE lines (labour) with separate revenue totals.
 */
export default function ShowServiceInvoice({ invoice: { data: invoice } }: { invoice: { data: ServiceInvoice } }) {
    const can = useCan();
    const showCost = invoice.cost_total !== undefined;
    const rows: [string, string, boolean?][] = [
        ['Subtotal', formatMoney(invoice.subtotal)],
        ...(toCents(invoice.discount) > 0 ? [['Discount', `−${formatMoney(invoice.discount)}`] as [string, string]] : []),
        ['Total', formatMoney(invoice.total), true],
        [`Paid${invoice.payment_method_label ? ` (${invoice.payment_method_label})` : ''}`, formatMoney(invoice.paid_amount)],
        ['Due', formatMoney(invoice.due_amount), true],
    ];

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Service invoices', href: route('service-invoices.index') },
                { title: invoice.invoice_no, href: route('service-invoices.show', invoice.id) },
            ]}
        >
            <Head title={invoice.invoice_no} />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="space-y-1">
                        <h2 className="flex items-center gap-2 font-mono text-xl font-semibold">
                            {invoice.invoice_no}
                            <PaymentStatusBadge status={invoice.payment_status} label={invoice.payment_status_label} />
                        </h2>
                        <p className="text-muted-foreground text-sm">
                            {formatDateTime(invoice.invoiced_at)} ·{' '}
                            {invoice.party && (
                                <Link href={route('parties.show', invoice.party.id)} className="hover:underline">
                                    {invoice.party.name}
                                </Link>
                            )}
                            {invoice.party?.phone && ` (${invoice.party.phone})`}
                            {invoice.created_by && ` · Billed by ${invoice.created_by}`}
                        </p>
                        {invoice.job && (
                            <p className="text-sm">
                                Job{' '}
                                <Link href={route('service-jobs.show', invoice.job.id)} className="font-mono hover:underline">
                                    {invoice.job.job_no}
                                </Link>{' '}
                                · {invoice.job.device?.name}
                                {invoice.job.device?.imei1 && (
                                    <span className="text-muted-foreground font-mono text-xs"> IMEI {invoice.job.device.imei1}</span>
                                )}
                            </p>
                        )}
                    </div>
                    {can('sales.collect') && toCents(invoice.due_amount) > 0 && invoice.party && (
                        <Button variant="secondary" asChild>
                            <Link href={route('customer-payments.create', { party_id: invoice.party.id, service_invoice_id: invoice.id })}>
                                Collect due
                            </Link>
                        </Button>
                    )}
                </div>

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Line</th>
                                    <th className="px-4 py-3 font-medium">Type</th>
                                    <th className="px-4 py-3 text-right font-medium">Qty</th>
                                    <th className="px-4 py-3 text-right font-medium">Price</th>
                                    <th className="px-4 py-3 text-right font-medium">Discount</th>
                                    <th className="px-4 py-3 text-right font-medium">Total</th>
                                    {showCost && <th className="px-4 py-3 text-right font-medium">Unit cost</th>}
                                </tr>
                            </thead>
                            <tbody>
                                {invoice.items?.map((line) => (
                                    <tr key={line.id} className="border-t">
                                        <td className="px-4 py-3">
                                            <div className="font-medium">{line.description}</div>
                                            {line.sku && <div className="text-muted-foreground font-mono text-xs">{line.sku}</div>}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge variant={line.line_type === 'PRODUCT' ? 'secondary' : 'outline'}>{line.line_type_label}</Badge>
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums">{line.quantity}</td>
                                        <td className="px-4 py-3 text-right tabular-nums">{formatMoney(line.unit_price)}</td>
                                        <td className="px-4 py-3 text-right tabular-nums">
                                            {toCents(line.discount_share) > 0 ? formatMoney(line.discount_share) : '—'}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums">{formatMoney(line.line_total)}</td>
                                        {showCost && (
                                            <td className="text-muted-foreground px-4 py-3 text-right tabular-nums">{formatMoney(line.unit_cost)}</td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="space-y-4">
                        <Card>
                            <CardContent className="space-y-1 pt-6 text-sm">
                                <div className="flex justify-between">
                                    <span>Parts (product revenue)</span>
                                    <span className="tabular-nums">{formatMoney(invoice.product_total)}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span>Service revenue</span>
                                    <span className="tabular-nums">{formatMoney(invoice.service_total)}</span>
                                </div>
                                <dl className="mt-2 space-y-1 border-t pt-2">
                                    {rows.map(([label, value, strong]) => (
                                        <div key={label} className={strong ? 'flex justify-between font-semibold' : 'flex justify-between'}>
                                            <dt>{label}</dt>
                                            <dd className="tabular-nums">{value}</dd>
                                        </div>
                                    ))}
                                </dl>
                                {showCost && (
                                    <p className="text-muted-foreground mt-3 flex justify-between border-t pt-2 text-xs">
                                        <span>Parts cost (snapshot)</span>
                                        <span className="tabular-nums">{formatMoney(invoice.cost_total)}</span>
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                        <div className="rounded-lg border">
                            <h3 className="border-b px-4 py-2 text-sm font-medium">Payments</h3>
                            {invoice.allocations?.length === 0 && <p className="text-muted-foreground px-4 py-3 text-sm">No payments received.</p>}
                            <ul className="divide-y text-sm">
                                {invoice.allocations?.map((allocation) => (
                                    <li key={allocation.id} className="flex justify-between gap-2 px-4 py-2">
                                        <span>
                                            <Link href={route('customer-payments.show', allocation.payment.id)} className="font-mono hover:underline">
                                                {allocation.payment.payment_no}
                                            </Link>
                                            <span className="text-muted-foreground ml-2 text-xs">
                                                {allocation.payment.method_label} · {formatDateTime(allocation.payment.paid_at)}
                                            </span>
                                        </span>
                                        <span className="tabular-nums">{formatMoney(allocation.amount)}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                        {invoice.notes && <p className="text-muted-foreground text-sm">Note: {invoice.notes}</p>}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
