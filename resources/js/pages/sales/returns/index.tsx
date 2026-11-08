import Heading from '@/components/heading';
import { Pagination } from '@/components/pagination';
import { type SaleReturn } from '@/features/sales/types';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatMoney, toCents } from '@/lib/format';
import { type Paginated } from '@/types';
import { Head, Link } from '@inertiajs/react';

export default function SaleReturnsIndex({ returns }: { returns: Paginated<SaleReturn> }) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Sale returns', href: route('sale-returns.index') }]}>
            <Head title="Sale returns" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading title="Sale returns" description="Goods returned by customers. Start a return from the original sale." />
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-4 py-3 font-medium">Return</th>
                                <th className="px-4 py-3 font-medium">Sale</th>
                                <th className="px-4 py-3 font-medium">Customer</th>
                                <th className="px-4 py-3 text-right font-medium">Value</th>
                                <th className="px-4 py-3 text-right font-medium">Refunded</th>
                                <th className="px-4 py-3 text-right font-medium">Credited</th>
                            </tr>
                        </thead>
                        <tbody>
                            {returns.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground px-4 py-8 text-center">
                                        No sale returns yet.
                                    </td>
                                </tr>
                            )}
                            {returns.data.map((row) => (
                                <tr key={row.id} className="border-t">
                                    <td className="px-4 py-3">
                                        <Link
                                            href={route('sale-returns.show', row.id)}
                                            className="font-mono font-medium whitespace-nowrap hover:underline"
                                        >
                                            {row.return_no}
                                        </Link>
                                        <div className="text-muted-foreground text-xs">
                                            {formatDateTime(row.returned_at)}
                                            {row.created_by && ` · ${row.created_by}`}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        {row.sale && (
                                            <Link href={route('sales.show', row.sale.id)} className="font-mono whitespace-nowrap hover:underline">
                                                {row.sale.invoice_no}
                                            </Link>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">{row.party?.name ?? 'Walk-in'}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">{formatMoney(row.subtotal)}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {toCents(row.refund_amount) > 0 ? formatMoney(row.refund_amount) : '—'}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {toCents(row.credit_amount) > 0 ? formatMoney(row.credit_amount) : '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination meta={returns.meta} />
            </div>
        </AppLayout>
    );
}
