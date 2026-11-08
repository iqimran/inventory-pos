import { Button } from '@/components/ui/button';
import { BarcodeImage } from '@/features/printing/barcode-image';
import { Divider, DocumentHeader, type PrintShop } from '@/features/printing/document-header';
import { PrintPage } from '@/features/printing/print-page';
import { usePaperWidth } from '@/features/printing/use-paper-width';
import { type ServiceInvoice, type ServiceInvoiceLine } from '@/features/service/types';
import { formatMoney, fromCents, toCents } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';

interface PrintProps {
    invoice: { data: ServiceInvoice };
    shop: PrintShop;
    invoiceBarcode: string;
    autoPrint: boolean;
}

function Pair({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
    return (
        <div className={strong ? 'flex justify-between gap-2 font-bold' : 'flex justify-between gap-2'}>
            <dt>{label}</dt>
            <dd className="text-right tabular-nums">{value}</dd>
        </div>
    );
}

const SECTIONS = {
    PRODUCT: { title: 'PARTS / PRODUCTS', tag: 'P', subtotal: 'Parts subtotal', rule: 'border-y border-black' },
    SERVICE: { title: 'SERVICE / LABOR', tag: 'S', subtotal: 'Service subtotal', rule: 'border-y-4 border-double border-black' },
} as const;

/**
 * One section of the invoice. PRODUCT (parts, which left stock) and SERVICE (labour) lines are printed
 * in separately headed and ruled blocks, each line tagged [P] / [S], so the two never blur together.
 */
function LineSection({ type, lines }: { type: keyof typeof SECTIONS; lines: ServiceInvoiceLine[] }) {
    if (lines.length === 0) return null;

    const section = SECTIONS[type];
    const subtotal = fromCents(lines.reduce((sum, line) => sum + toCents(line.line_subtotal), 0));

    return (
        <section className="mb-3" aria-label={section.title}>
            <h2 className={cn('mb-1 py-0.5 text-center font-bold tracking-wider', section.rule)}>{section.title}</h2>
            <table className="w-full">
                <tbody>
                    {lines.map((line) => (
                        <tr key={line.id} className="align-top">
                            <td className="w-5 py-0.5 font-bold">[{section.tag}]</td>
                            <td className="py-0.5 pr-2">
                                <div>{line.description}</div>
                                {line.sku && <div className="text-[0.85em]">{line.sku}</div>}
                                {type === 'PRODUCT' && (
                                    <div>
                                        {line.quantity} × {formatMoney(line.unit_price)}
                                    </div>
                                )}
                            </td>
                            <td className="py-0.5 text-right tabular-nums">{formatMoney(line.line_subtotal)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
            <dl className="border-t border-dotted border-black pt-0.5">
                <Pair label={section.subtotal} value={formatMoney(subtotal)} strong />
            </dl>
        </section>
    );
}

/**
 * Customer copy of a service invoice for 80 mm / 58 mm thermal paper (readable on any printer or screen).
 */
export default function PrintServiceInvoice({ invoice: { data: invoice }, shop, invoiceBarcode, autoPrint }: PrintProps) {
    const paper = usePaperWidth();
    const parts = invoice.items?.filter((line) => line.line_type === 'PRODUCT') ?? [];
    const services = invoice.items?.filter((line) => line.line_type === 'SERVICE') ?? [];
    const device = invoice.job?.device;

    return (
        <PrintPage
            title={`Service invoice ${invoice.invoice_no}`}
            paper={{ roll: paper.width }}
            autoPrint={autoPrint}
            className={paper.maxWidthClass}
            actions={
                <>
                    <Button variant="outline" asChild>
                        <Link href={route('service-invoices.show', invoice.id)}>Details</Link>
                    </Button>
                    {invoice.job && (
                        <Button variant="outline" asChild>
                            <Link href={route('service-jobs.show', invoice.job.id)}>Job</Link>
                        </Button>
                    )}
                    {paper.toggle}
                </>
            }
        >
            <article
                className={cn(
                    'mx-auto w-full bg-white p-4 font-mono leading-snug text-black shadow print:px-[3mm] print:py-[2mm] print:shadow-none',
                    paper.maxWidthClass,
                    paper.textClass,
                )}
            >
                <DocumentHeader shop={shop} title="SERVICE INVOICE" />

                <Divider />

                <dl className="space-y-0.5">
                    <Pair label="Invoice" value={invoice.invoice_no} strong />
                    <Pair label="Date" value={new Date(invoice.invoiced_at).toLocaleString()} />
                    {invoice.job && <Pair label="Job" value={invoice.job.job_no} />}
                    {invoice.job && <Pair label="Received" value={new Date(invoice.job.received_at).toLocaleDateString()} />}
                    {invoice.party && (
                        <Pair label="Customer" value={`${invoice.party.name}${invoice.party.phone ? ` (${invoice.party.phone})` : ''}`} />
                    )}
                    {invoice.created_by && <Pair label="Billed by" value={invoice.created_by} />}
                </dl>

                {device && (
                    <>
                        <Divider />
                        <dl className="space-y-0.5">
                            <Pair label="Device" value={device.name} />
                            {device.imei1 && <Pair label="IMEI 1" value={device.imei1} />}
                            {device.imei2 && <Pair label="IMEI 2" value={device.imei2} />}
                            {device.serial_no && <Pair label="Serial" value={device.serial_no} />}
                        </dl>
                    </>
                )}

                <Divider />

                <LineSection type="PRODUCT" lines={parts} />
                <LineSection type="SERVICE" lines={services} />

                {/* Parts and service subtotals are printed with their sections above. */}
                <dl className="space-y-0.5 border-t border-black pt-1">
                    {toCents(invoice.discount) > 0 && (
                        <>
                            <Pair label="Subtotal" value={formatMoney(invoice.subtotal)} />
                            <Pair label="Discount" value={`−${formatMoney(invoice.discount)}`} />
                        </>
                    )}
                    <Pair label="TOTAL" value={formatMoney(invoice.total)} strong />
                    <Pair label="Total paid" value={formatMoney(invoice.paid_amount)} />
                    {toCents(invoice.due_amount) > 0 && <Pair label="Due" value={formatMoney(invoice.due_amount)} strong />}
                </dl>

                {invoice.notes && <p className="mt-2">Note: {invoice.notes}</p>}

                <Divider />

                <BarcodeImage src={invoiceBarcode} value={invoice.invoice_no} className="mx-auto h-10 max-w-[60mm]" />

                <div className="mt-6 flex justify-between gap-4 text-center text-[0.9em]">
                    <div className="flex-1 border-t border-black pt-0.5">Customer</div>
                    <div className="flex-1 border-t border-black pt-0.5">Authorised</div>
                </div>

                {shop.receipt_footer && <p className="mt-3 text-center">{shop.receipt_footer}</p>}
            </article>
        </PrintPage>
    );
}
