import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PaymentStatusBadge } from '@/features/purchasing/status-badge';
import { type Purchase } from '@/features/purchasing/types';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatMoney, toCents } from '@/lib/format';
import { Head, Link, router, usePage } from '@inertiajs/react';

export default function ShowPurchase({
    purchase: { data: purchase },
    applicableAdvance,
}: {
    purchase: { data: Purchase };
    applicableAdvance: string;
}) {
    const can = useCan();
    const { errors } = usePage().props as { errors: Record<string, string> };
    const hasReturnable = purchase.items?.some((item) => item.returnable_quantity > 0);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Purchases', href: route('purchases.index') },
                { title: purchase.purchase_no, href: route('purchases.show', purchase.id) },
            ]}
        >
            <Head title={purchase.purchase_no} />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="space-y-1">
                        <h2 className="flex items-center gap-2 font-mono text-xl font-semibold">
                            {purchase.purchase_no}
                            <PaymentStatusBadge status={purchase.payment_status} label={purchase.payment_status_label} />
                        </h2>
                        <p className="text-muted-foreground text-sm">
                            {purchase.purchase_date} ·{' '}
                            {purchase.party && (
                                <Link href={route('parties.show', purchase.party.id)} className="hover:underline">
                                    {purchase.party.name}
                                </Link>
                            )}
                            {purchase.supplier_invoice_no && ` · Invoice ${purchase.supplier_invoice_no}`}
                            {purchase.created_by && ` · Recorded by ${purchase.created_by}`}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {can('payments.create') && toCents(purchase.due_amount) > 0 && purchase.party && (
                            <Button asChild>
                                <Link href={route('supplier-payments.create', { party_id: purchase.party.id, purchase_id: purchase.id })}>
                                    Record payment
                                </Link>
                            </Button>
                        )}
                        {can('payments.create') && toCents(applicableAdvance) > 0 && (
                            <Button
                                variant="secondary"
                                onClick={() => router.post(route('purchases.apply-advance', purchase.id), {}, { preserveScroll: true })}
                            >
                                Apply advance ({formatMoney(applicableAdvance)})
                            </Button>
                        )}
                        {can('purchases.return') && hasReturnable && (
                            <Button variant="outline" asChild>
                                <Link href={route('purchases.returns.create', purchase.id)}>Return items</Link>
                            </Button>
                        )}
                    </div>
                </div>

                <InputError message={errors.advance} />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {[
                        ['Total', purchase.total],
                        ['Paid', purchase.paid_amount],
                        ['Returned', purchase.returned_amount],
                        ['Due', purchase.due_amount],
                    ].map(([label, value]) => (
                        <Card key={label}>
                            <CardHeader>
                                <CardTitle className="text-sm font-medium">{label}</CardTitle>
                            </CardHeader>
                            <CardContent className="text-lg font-semibold tabular-nums">{formatMoney(value)}</CardContent>
                        </Card>
                    ))}
                </div>

                <section className="space-y-3">
                    <h3 className="font-medium">Items</h3>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Product</th>
                                    <th className="px-4 py-3 text-right font-medium">Qty</th>
                                    <th className="px-4 py-3 text-right font-medium">Unit cost</th>
                                    <th className="px-4 py-3 text-right font-medium">Discount</th>
                                    <th className="px-4 py-3 text-right font-medium">Net total</th>
                                    <th className="px-4 py-3 text-right font-medium">Returned</th>
                                </tr>
                            </thead>
                            <tbody>
                                {purchase.items?.map((item) => (
                                    <tr key={item.id} className="border-t">
                                        <td className="px-4 py-3">
                                            <Link href={route('products.show', item.product_id)} className="font-medium hover:underline">
                                                {item.product?.name}
                                            </Link>
                                            <div className="text-muted-foreground font-mono text-xs">{item.product?.sku}</div>
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums">{item.quantity}</td>
                                        <td className="px-4 py-3 text-right tabular-nums">{formatMoney(item.unit_cost)}</td>
                                        <td className="px-4 py-3 text-right tabular-nums">{formatMoney(item.discount_share)}</td>
                                        <td className="px-4 py-3 text-right tabular-nums">{formatMoney(item.line_total)}</td>
                                        <td className="px-4 py-3 text-right tabular-nums">{item.returned_quantity}</td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot className="border-t text-sm">
                                <tr>
                                    <td colSpan={4} className="px-4 py-2 text-right">
                                        Subtotal
                                    </td>
                                    <td className="px-4 py-2 text-right tabular-nums">{formatMoney(purchase.subtotal)}</td>
                                    <td />
                                </tr>
                                <tr>
                                    <td colSpan={4} className="px-4 py-2 text-right">
                                        Discount
                                    </td>
                                    <td className="px-4 py-2 text-right tabular-nums">−{formatMoney(purchase.discount)}</td>
                                    <td />
                                </tr>
                                <tr className="font-medium">
                                    <td colSpan={4} className="px-4 py-2 text-right">
                                        Total
                                    </td>
                                    <td className="px-4 py-2 text-right tabular-nums">{formatMoney(purchase.total)}</td>
                                    <td />
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </section>

                <div className="grid gap-6 lg:grid-cols-2">
                    <section className="space-y-3">
                        <h3 className="font-medium">Payments applied</h3>
                        <div className="rounded-lg border">
                            {purchase.allocations?.length === 0 && <p className="text-muted-foreground p-4 text-sm">No payments yet.</p>}
                            <ul className="divide-y text-sm">
                                {purchase.allocations?.map((allocation) => (
                                    <li key={allocation.id} className="flex justify-between gap-4 px-4 py-2">
                                        {allocation.payment ? (
                                            <span>
                                                <Link
                                                    href={route('supplier-payments.show', allocation.payment.id)}
                                                    className="font-mono whitespace-nowrap hover:underline"
                                                >
                                                    {allocation.payment.payment_no}
                                                </Link>
                                                <span className="text-muted-foreground ml-2 text-xs">
                                                    {allocation.payment.purpose_label} · {allocation.payment.method_label} ·{' '}
                                                    {formatDateTime(allocation.payment.paid_at)}
                                                </span>
                                            </span>
                                        ) : (
                                            <span>
                                                Opening balance advance
                                                {allocation.created_at && (
                                                    <span className="text-muted-foreground ml-2 text-xs">
                                                        {formatDateTime(allocation.created_at)}
                                                    </span>
                                                )}
                                            </span>
                                        )}
                                        <span className="tabular-nums">{formatMoney(allocation.amount)}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </section>
                    <section className="space-y-3">
                        <h3 className="font-medium">Returns</h3>
                        <div className="rounded-lg border">
                            {purchase.returns?.length === 0 && <p className="text-muted-foreground p-4 text-sm">No returns.</p>}
                            <ul className="divide-y text-sm">
                                {purchase.returns?.map((purchaseReturn) => (
                                    <li key={purchaseReturn.id} className="flex justify-between gap-4 px-4 py-2">
                                        <span>
                                            <span className="font-mono">{purchaseReturn.return_no}</span>
                                            <span className="text-muted-foreground ml-2 text-xs">
                                                {purchaseReturn.return_date} · {purchaseReturn.reason}
                                                {toCents(purchaseReturn.refund_amount) > 0 &&
                                                    ` · refund ${formatMoney(purchaseReturn.refund_amount)}`}
                                            </span>
                                        </span>
                                        <span className="tabular-nums">{formatMoney(purchaseReturn.total)}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </section>
                </div>

                {purchase.notes && <p className="text-muted-foreground text-sm">Notes: {purchase.notes}</p>}
            </div>
        </AppLayout>
    );
}
