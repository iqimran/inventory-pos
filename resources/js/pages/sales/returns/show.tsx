import { Button } from '@/components/ui/button';
import { type SaleReturn } from '@/features/sales/types';
import { formatMoney, toCents } from '@/lib/format';
import { Head, Link } from '@inertiajs/react';
import { Printer } from 'lucide-react';

interface ShowSaleReturnProps {
    saleReturn: { data: SaleReturn };
    shop: { name: string; address: string | null; phone: string | null; logo_url: string | null };
}

/**
 * Return slip: what came back, what it was worth, and how it was settled.
 */
export default function ShowSaleReturn({ saleReturn: { data: saleReturn }, shop }: ShowSaleReturnProps) {
    // Always show the value; show each settlement line only when it applies.
    const settlement = (
        [
            ['Return value', saleReturn.subtotal, true],
            ['Applied to amount due', saleReturn.adjustment_amount, false],
            [`Refunded${saleReturn.refund_method_label ? ` (${saleReturn.refund_method_label})` : ''}`, saleReturn.refund_amount, false],
            ['Kept as store credit', saleReturn.credit_amount, false],
        ] as [string, string, boolean][]
    ).filter(([, amount, always]) => always || toCents(amount) > 0);

    return (
        <div className="bg-muted/40 min-h-svh py-6 print:bg-white print:py-0">
            <Head title={`Return ${saleReturn.return_no}`} />

            <div className="mx-auto mb-4 flex w-full max-w-[80mm] flex-wrap justify-center gap-2 px-2 print:hidden">
                <Button onClick={() => window.print()}>
                    <Printer className="size-4" /> Print
                </Button>
                {saleReturn.sale && (
                    <Button variant="outline" asChild>
                        <Link href={route('sales.show', saleReturn.sale.id)}>Original sale</Link>
                    </Button>
                )}
                <Button variant="outline" asChild>
                    <Link href={route('sale-returns.index')}>All returns</Link>
                </Button>
            </div>

            <article className="mx-auto w-full max-w-[80mm] bg-white p-4 font-mono text-[12px] leading-snug text-black shadow print:max-w-none print:p-0 print:shadow-none">
                <header className="text-center">
                    {shop.logo_url && <img src={shop.logo_url} alt="" className="mx-auto mb-1 max-h-16 max-w-[50mm] object-contain" />}
                    <h1 className="text-base font-bold">{shop.name}</h1>
                    {shop.address && <p className="whitespace-pre-line">{shop.address}</p>}
                    {shop.phone && <p>Tel: {shop.phone}</p>}
                    <p className="mt-1 font-bold">SALE RETURN</p>
                </header>

                <div className="my-2 border-t border-dashed border-black" />

                <dl className="space-y-0.5">
                    <div className="flex justify-between">
                        <dt>Return</dt>
                        <dd className="font-bold">{saleReturn.return_no}</dd>
                    </div>
                    <div className="flex justify-between">
                        <dt>Original sale</dt>
                        <dd>{saleReturn.sale?.invoice_no}</dd>
                    </div>
                    <div className="flex justify-between">
                        <dt>Date</dt>
                        <dd>{new Date(saleReturn.returned_at).toLocaleString()}</dd>
                    </div>
                    <div className="flex justify-between">
                        <dt>Customer</dt>
                        <dd className="text-right">{saleReturn.party?.name ?? 'Walk-in'}</dd>
                    </div>
                    {saleReturn.created_by && (
                        <div className="flex justify-between">
                            <dt>Handled by</dt>
                            <dd>{saleReturn.created_by}</dd>
                        </div>
                    )}
                </dl>

                <div className="my-2 border-t border-dashed border-black" />

                <table className="w-full">
                    <tbody>
                        {saleReturn.items?.map((item) => (
                            <tr key={item.id} className="align-top">
                                <td className="py-0.5 pr-2">
                                    <div>{item.product?.name}</div>
                                    <div>
                                        {item.quantity} × {formatMoney(item.unit_price)}
                                    </div>
                                </td>
                                <td className="py-0.5 text-right tabular-nums">{formatMoney(item.amount)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                <div className="my-2 border-t border-dashed border-black" />

                <dl className="space-y-0.5">
                    {settlement.map(([label, amount]) => (
                        <div key={label} className="flex justify-between">
                            <dt>{label}</dt>
                            <dd className="tabular-nums">{formatMoney(amount)}</dd>
                        </div>
                    ))}
                </dl>

                <p className="mt-2">Reason: {saleReturn.reason}</p>
            </article>
        </div>
    );
}
