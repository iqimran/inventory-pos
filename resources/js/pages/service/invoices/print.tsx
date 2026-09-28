import { Button } from '@/components/ui/button';
import { type ServiceInvoice, type ServiceInvoiceLine } from '@/features/service/types';
import { formatMoney, fromCents, toCents } from '@/lib/format';
import { Head, Link } from '@inertiajs/react';
import { Printer } from 'lucide-react';

interface PrintProps {
    invoice: { data: ServiceInvoice };
    shop: { name: string; address: string | null; phone: string | null; receipt_footer: string | null; logo_url: string | null };
}

const Divider = () => <div className="my-2 border-t border-dashed border-black" />;

function Pair({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
    return (
        <div className={strong ? 'flex justify-between font-bold' : 'flex justify-between'}>
            <dt>{label}</dt>
            <dd className="text-right tabular-nums">{value}</dd>
        </div>
    );
}

function LineSection({ title, lines, subtotalLabel }: { title: string; lines: ServiceInvoiceLine[]; subtotalLabel: string }) {
    if (lines.length === 0) return null;

    const subtotal = fromCents(lines.reduce((sum, line) => sum + toCents(line.line_subtotal), 0));

    return (
        <section className="mb-2">
            <h2 className="font-bold uppercase">{title}</h2>
            <table className="w-full">
                <tbody>
                    {lines.map((line) => (
                        <tr key={line.id} className="align-top">
                            <td className="py-0.5 pr-2">
                                <div>{line.description}</div>
                                {line.line_type === 'PRODUCT' && (
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
                <Pair label={subtotalLabel} value={formatMoney(subtotal)} />
            </dl>
        </section>
    );
}

/**
 * Customer copy of a service invoice, laid out for 80mm thermal paper but readable on any printer or screen.
 * Parts (product lines) and service charges (service lines) are printed as separate sections.
 */
export default function PrintServiceInvoice({ invoice: { data: invoice }, shop }: PrintProps) {
    const parts = invoice.items?.filter((line) => line.line_type === 'PRODUCT') ?? [];
    const services = invoice.items?.filter((line) => line.line_type === 'SERVICE') ?? [];
    const device = invoice.job?.device;

    return (
        <div className="bg-muted/40 min-h-svh py-6 print:bg-white print:py-0">
            <Head title={`Service invoice ${invoice.invoice_no}`} />

            <div className="mx-auto mb-4 flex w-full max-w-[80mm] flex-wrap justify-center gap-2 px-2 print:hidden">
                <Button onClick={() => window.print()}>
                    <Printer className="size-4" /> Print
                </Button>
                <Button variant="outline" asChild>
                    <Link href={route('service-invoices.show', invoice.id)}>Details</Link>
                </Button>
                {invoice.job && (
                    <Button variant="outline" asChild>
                        <Link href={route('service-jobs.show', invoice.job.id)}>Job</Link>
                    </Button>
                )}
            </div>

            <article className="mx-auto w-full max-w-[80mm] bg-white p-4 font-mono text-[12px] leading-snug text-black shadow print:max-w-none print:p-0 print:shadow-none">
                <header className="text-center">
                    {shop.logo_url && <img src={shop.logo_url} alt="" className="mx-auto mb-1 max-h-16 max-w-[50mm] object-contain" />}
                    <h1 className="text-base font-bold">{shop.name}</h1>
                    {shop.address && <p className="whitespace-pre-line">{shop.address}</p>}
                    {shop.phone && <p>Tel: {shop.phone}</p>}
                    <p className="mt-1 font-bold">SERVICE INVOICE</p>
                </header>

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
                        {invoice.job?.complaint && <p className="mt-1">Complaint: {invoice.job.complaint}</p>}
                        {invoice.job?.diagnosis && <p>Work done: {invoice.job.diagnosis}</p>}
                    </>
                )}

                <Divider />

                <LineSection title="Parts" lines={parts} subtotalLabel="Parts subtotal" />
                <LineSection title="Service charges" lines={services} subtotalLabel="Service subtotal" />

                <Divider />

                <dl className="space-y-0.5">
                    <Pair label="Subtotal" value={formatMoney(invoice.subtotal)} />
                    {toCents(invoice.discount) > 0 && <Pair label="Discount" value={`−${formatMoney(invoice.discount)}`} />}
                    <Pair label="TOTAL" value={formatMoney(invoice.total)} strong />
                    {invoice.allocations?.map((allocation) => (
                        <Pair
                            key={allocation.id}
                            label={`Paid ${allocation.payment.method_label} ${new Date(allocation.payment.paid_at).toLocaleDateString()}`}
                            value={formatMoney(allocation.amount)}
                        />
                    ))}
                    <Pair label="Total paid" value={formatMoney(invoice.paid_amount)} />
                    <Pair label="Due" value={formatMoney(invoice.due_amount)} strong />
                </dl>

                {invoice.payment_status !== 'PAID' && (
                    <p className="mt-2 text-center font-bold">
                        {invoice.payment_status_label.toUpperCase()} — balance due {formatMoney(invoice.due_amount)}
                    </p>
                )}

                {invoice.notes && <p className="mt-2">Note: {invoice.notes}</p>}

                <Divider />

                <div className="mt-6 flex justify-between gap-4 text-center text-[11px]">
                    <div className="flex-1 border-t border-black pt-0.5">Customer</div>
                    <div className="flex-1 border-t border-black pt-0.5">Authorised</div>
                </div>

                {shop.receipt_footer && <p className="mt-3 text-center">{shop.receipt_footer}</p>}
            </article>
        </div>
    );
}
