import Heading from '@/components/heading';
import { Pagination } from '@/components/pagination';
import AppLayout from '@/layouts/app-layout';
import { formatMoney, toCents } from '@/lib/format';
import { type Paginator } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface ReturnRow {
    id: number;
    return_no: string;
    return_date: string;
    purchase: { id: number; purchase_no: string };
    party: { id: number; name: string };
    total: string;
    refund_amount: string;
    reason: string;
    created_by: string | null;
}

export default function PurchaseReturnsIndex({ returns }: { returns: Paginator<ReturnRow> }) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Purchase returns', href: route('purchase-returns.index') }]}>
            <Head title="Purchase returns" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading title="Purchase returns" description="Goods returned to suppliers. Start a return from the original purchase." />
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-4 py-3 font-medium">Return</th>
                                <th className="px-4 py-3 font-medium">Purchase</th>
                                <th className="px-4 py-3 font-medium">Supplier</th>
                                <th className="px-4 py-3 font-medium">Reason</th>
                                <th className="px-4 py-3 text-right font-medium">Value</th>
                                <th className="px-4 py-3 text-right font-medium">Refund</th>
                            </tr>
                        </thead>
                        <tbody>
                            {returns.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground px-4 py-8 text-center">
                                        No purchase returns yet.
                                    </td>
                                </tr>
                            )}
                            {returns.data.map((row) => (
                                <tr key={row.id} className="border-t">
                                    <td className="px-4 py-3">
                                        <div className="font-mono">{row.return_no}</div>
                                        <div className="text-muted-foreground text-xs">
                                            {row.return_date}
                                            {row.created_by && ` · ${row.created_by}`}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        <Link href={route('purchases.show', row.purchase.id)} className="font-mono hover:underline">
                                            {row.purchase.purchase_no}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3">{row.party.name}</td>
                                    <td className="px-4 py-3">{row.reason}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">{formatMoney(row.total)}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {toCents(row.refund_amount) > 0 ? formatMoney(row.refund_amount) : '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination meta={returns} />
            </div>
        </AppLayout>
    );
}
