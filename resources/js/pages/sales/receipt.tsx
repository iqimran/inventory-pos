import { Button } from '@/components/ui/button';
import { BarcodeImage } from '@/features/printing/barcode-image';
import { Divider, DocumentHeader, type PrintShop } from '@/features/printing/document-header';
import { PrintPage } from '@/features/printing/print-page';
import { usePaperWidth } from '@/features/printing/use-paper-width';
import { SaleTotals } from '@/features/sales/receipt-lines';
import { type Sale } from '@/features/sales/types';
import { useCan } from '@/hooks/use-can';
import { formatMoney, toCents } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { useEffect } from 'react';

interface ReceiptProps {
    sale: { data: Sale };
    shop: PrintShop;
    invoiceBarcode: string;
    autoPrint: boolean;
    justCompleted: boolean;
}

/**
 * Customer sale slip for 80 mm or 58 mm thermal paper (readable on any printer or screen).
 */
export default function SaleReceipt({ sale: { data: sale }, shop, invoiceBarcode, autoPrint, justCompleted }: ReceiptProps) {
    const can = useCan();
    const paper = usePaperWidth();
    const itemCount = sale.items?.reduce((sum, item) => sum + item.quantity, 0) ?? 0;

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
        <PrintPage
            title={`Receipt ${sale.invoice_no}`}
            paper={{ roll: paper.width }}
            autoPrint={autoPrint}
            className={paper.maxWidthClass}
            actions={
                <>
                    {can('sales.create') && (
                        <Button variant="secondary" asChild>
                            <Link href={route('pos.index', { mode: sale.sale_type })}>New sale</Link>
                        </Button>
                    )}
                    <Button variant="outline" asChild>
                        <Link href={route('sales.show', sale.id)}>Details</Link>
                    </Button>
                    {paper.toggle}
                </>
            }
            hint={justCompleted ? 'Press Enter or F2 for the next sale.' : undefined}
        >
            <article
                className={cn(
                    'mx-auto w-full bg-white p-4 font-mono leading-snug text-black shadow print:px-[3mm] print:py-[2mm] print:shadow-none',
                    paper.maxWidthClass,
                    paper.textClass,
                )}
            >
                <DocumentHeader shop={shop} />

                <Divider />

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
                    <div className="flex justify-between gap-2">
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

                <Divider />

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
                                    {item.product?.sku && <div className="text-[0.85em]">{item.product.sku}</div>}
                                    <div>
                                        {item.quantity} × {formatMoney(item.unit_price)}
                                    </div>
                                    {toCents(item.line_discount) > 0 && <div>Discount −{formatMoney(item.line_discount)}</div>}
                                </td>
                                <td className="py-0.5 text-right tabular-nums">{formatMoney(item.line_subtotal)}</td>
                            </tr>
                        ))}
                        {sale.service_charges && sale.service_charges.length > 0 && (
                            <tr>
                                <td colSpan={2} className="pt-1 font-bold">
                                    Service
                                </td>
                            </tr>
                        )}
                        {sale.service_charges?.map((charge) => (
                            <tr key={`service-${charge.id}`} className="align-top">
                                <td className="py-0.5 pr-2">{charge.description}</td>
                                <td className="py-0.5 text-right tabular-nums">{formatMoney(charge.amount)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                <p className="mt-1 text-right">Items: {itemCount}</p>

                <Divider />

                <div className="[&_dl]:text-[1em]">
                    <SaleTotals sale={sale} />
                </div>

                {sale.payment_status !== 'PAID' && (
                    <p className="mt-2 text-center font-bold">
                        {sale.payment_status_label.toUpperCase()} — balance due {formatMoney(sale.due_amount)}
                    </p>
                )}

                <Divider />

                <BarcodeImage src={invoiceBarcode} value={sale.invoice_no} className="mx-auto h-10 max-w-[60mm]" />

                {shop.receipt_footer && <p className="mt-2 text-center">{shop.receipt_footer}</p>}
            </article>
        </PrintPage>
    );
}
