import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { PaymentStatusBadge } from '@/features/purchasing/status-badge';
import { SaleTotals } from '@/features/sales/receipt-lines';
import { type Sale } from '@/features/sales/types';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatMoney, fromCents, toCents } from '@/lib/format';
import { Head, Link } from '@inertiajs/react';

export default function ShowSale({ sale: { data: sale } }: { sale: { data: Sale } }) {
    const can = useCan();

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Sales', href: route('sales.index') },
                { title: sale.invoice_no, href: route('sales.show', sale.id) },
            ]}
        >
            <Head title={sale.invoice_no} />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="space-y-1">
                        <h2 className="flex items-center gap-2 font-mono text-xl font-semibold">
                            {sale.invoice_no}
                            <PaymentStatusBadge status={sale.payment_status} label={sale.payment_status_label} />
                        </h2>
                        <p className="text-muted-foreground text-sm">
                            {formatDateTime(sale.sold_at)} · {sale.sale_type_label} ·{' '}
                            {sale.party ? (
                                <Link href={route('parties.show', sale.party.id)} className="hover:underline">
                                    {sale.party.name}
                                </Link>
                            ) : (
                                'Walk-in customer'
                            )}
                            {sale.created_by && ` · Sold by ${sale.created_by}`}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild>
                            <Link href={route('sales.receipt', sale.id)}>Receipt</Link>
                        </Button>
                        {can('sales.collect') && toCents(sale.due_amount) > 0 && sale.party && (
                            <Button variant="secondary" asChild>
                                <Link href={route('customer-payments.create', { party_id: sale.party.id, sale_id: sale.id })}>Collect due</Link>
                            </Button>
                        )}
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Product</th>
                                    <th className="px-4 py-3 text-right font-medium">Qty</th>
                                    <th className="px-4 py-3 text-right font-medium">Price</th>
                                    <th className="px-4 py-3 text-right font-medium">Discount</th>
                                    <th className="px-4 py-3 text-right font-medium">Total</th>
                                    {sale.cost_total !== undefined && <th className="px-4 py-3 text-right font-medium">Unit cost</th>}
                                </tr>
                            </thead>
                            <tbody>
                                {sale.items?.map((item) => (
                                    <tr key={item.id} className="border-t">
                                        <td className="px-4 py-3">
                                            <div className="font-medium">{item.product?.name}</div>
                                            <div className="text-muted-foreground font-mono text-xs">{item.product?.sku}</div>
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums">{item.quantity}</td>
                                        <td className="px-4 py-3 text-right tabular-nums">
                                            {formatMoney(item.unit_price)}
                                            {item.price_overridden && (
                                                <div className="text-muted-foreground text-xs line-through">{formatMoney(item.list_price)}</div>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums">
                                            {toCents(item.line_discount) + toCents(item.discount_share) > 0
                                                ? formatMoney(fromCents(toCents(item.line_discount) + toCents(item.discount_share)))
                                                : '—'}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums">{formatMoney(item.line_total)}</td>
                                        {sale.cost_total !== undefined && (
                                            <td className="text-muted-foreground px-4 py-3 text-right tabular-nums">{formatMoney(item.unit_cost)}</td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="space-y-4">
                        <Card>
                            <CardContent className="pt-6">
                                <SaleTotals sale={sale} />
                                {sale.cost_total !== undefined && (
                                    <p className="text-muted-foreground mt-3 flex justify-between border-t pt-2 text-xs">
                                        <span>Cost of goods (snapshot)</span>
                                        <span className="tabular-nums">{formatMoney(sale.cost_total)}</span>
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                        <div className="rounded-lg border">
                            <h3 className="border-b px-4 py-2 text-sm font-medium">Payments</h3>
                            {sale.allocations?.length === 0 && <p className="text-muted-foreground px-4 py-3 text-sm">No payments received.</p>}
                            <ul className="divide-y text-sm">
                                {sale.allocations?.map((allocation) => (
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
                        {sale.notes && <p className="text-muted-foreground text-sm">Note: {sale.notes}</p>}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
