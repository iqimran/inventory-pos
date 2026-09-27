import { Button } from '@/components/ui/button';
import { SaleTotals } from '@/features/sales/receipt-lines';
import { type Sale } from '@/features/sales/types';
import { useCan } from '@/hooks/use-can';
import { formatMoney, toCents } from '@/lib/format';
import { Head, Link } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { useEffect } from 'react';

interface ReceiptProps {
    sale: { data: Sale };
    shop: { name: string; address: string | null; phone: string | null; receipt_footer: string | null };
    justCompleted: boolean;
}

/**
 * Customer sale slip, laid out for 80mm thermal paper but readable on any printer or screen.
 */
export default function SaleReceipt({ sale: { data: sale }, shop, justCompleted }: ReceiptProps) {
    const can = useCan();

    // After completing a sale, pressing Enter or F2 starts the next one.
    useEffect(() => {
        if (!justCompleted) return;

        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'F2' || e.key === 'Enter') {
                e.preventDefault();
                window.location.assign(route('pos.index', { mode: sale.sale_type }));
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [justCompleted, sale.sale_type]);

    return (
        <div className="bg-muted/40 min-h-svh py-6 print:bg-white print:py-0">
            <Head title={`Receipt ${sale.invoice_no}`} />

            <div className="mx-auto mb-4 flex w-full max-w-[80mm] flex-wrap justify-center gap-2 px-2 print:hidden">
                <Button onClick={() => window.print()}>
                    <Printer className="size-4" /> Print
                </Button>
                {can('sales.create') && (
                    <Button variant="secondary" asChild>
                        <Link href={route('pos.index', { mode: sale.sale_type })}>New sale</Link>
                    </Button>
                )}
                <Button variant="outline" asChild>
                    <Link href={route('sales.show', sale.id)}>Details</Link>
                </Button>
                {justCompleted && <p className="text-muted-foreground w-full text-center text-xs">Press Enter or F2 for the next sale.</p>}
            </div>

            <article className="mx-auto w-full max-w-[80mm] bg-white p-4 font-mono text-[12px] leading-snug text-black shadow print:max-w-none print:p-0 print:shadow-none">
                <header className="text-center">
                    <h1 className="text-base font-bold">{shop.name}</h1>
                    {shop.address && <p>{shop.address}</p>}
                    {shop.phone && <p>Tel: {shop.phone}</p>}
                </header>

                <div className="my-2 border-t border-dashed border-black" />

                <dl className="space-y-0.5">
                    <div className="flex justify-between">
                        <dt>Invoice</dt>
                        <dd className="font-bold">{sale.invoice_no}</dd>
                    </div>
                    <div className="flex justify-between">
                        <dt>Date</dt>
                        <dd>{new Date(sale.sold_at).toLocaleString()}</dd>
                    </div>
                    <div className="flex justify-between">
                        <dt>Type</dt>
                        <dd>{sale.sale_type_label}</dd>
                    </div>
                    <div className="flex justify-between">
                        <dt>Customer</dt>
                        <dd className="text-right">
                            {sale.party ? `${sale.party.name}${sale.party.phone ? ` (${sale.party.phone})` : ''}` : 'Walk-in'}
                        </dd>
                    </div>
                    {sale.created_by && (
                        <div className="flex justify-between">
                            <dt>Cashier</dt>
                            <dd>{sale.created_by}</dd>
                        </div>
                    )}
                </dl>

                <div className="my-2 border-t border-dashed border-black" />

                <table className="w-full">
                    <thead>
                        <tr className="text-left">
                            <th className="font-bold">Item</th>
                            <th className="text-right font-bold">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        {sale.items?.map((item) => (
                            <tr key={item.id} className="align-top">
                                <td className="py-0.5 pr-2">
                                    <div>{item.product?.name}</div>
                                    <div>
                                        {item.quantity} × {formatMoney(item.unit_price)}
                                    </div>
                                    {toCents(item.line_discount) > 0 && <div>Discount −{formatMoney(item.line_discount)}</div>}
                                </td>
                                <td className="py-0.5 text-right tabular-nums">{formatMoney(item.line_subtotal)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                <div className="my-2 border-t border-dashed border-black" />

                <div className="[&_dl]:text-[12px]">
                    <SaleTotals sale={sale} />
                </div>

                {sale.payment_status !== 'PAID' && (
                    <p className="mt-2 text-center font-bold">
                        {sale.payment_status_label.toUpperCase()} — balance due {formatMoney(sale.due_amount)}
                    </p>
                )}

                <div className="my-2 border-t border-dashed border-black" />

                {shop.receipt_footer && <p className="text-center">{shop.receipt_footer}</p>}
            </article>
        </div>
    );
}
