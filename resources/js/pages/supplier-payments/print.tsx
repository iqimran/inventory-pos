import { Button } from '@/components/ui/button';
import { type Payment } from '@/features/purchasing/types';
import { formatMoney, toCents } from '@/lib/format';
import { Head, Link } from '@inertiajs/react';
import { Printer } from 'lucide-react';

interface PrintProps {
    payment: { data: Payment };
    shop: { name: string; address: string | null; phone: string | null; logo_url: string | null };
}

/**
 * Printable payment voucher (A5 / A4), with the organization header and signature lines.
 */
export default function PrintSupplierPayment({ payment: { data: payment }, shop }: PrintProps) {
    const outgoing = payment.direction === 'OUT';
    const rows: [string, string][] = [
        ['Voucher no.', payment.payment_no],
        ['Date', new Date(payment.paid_at).toLocaleString()],
        ['Type', payment.purpose_label],
        ['Method', payment.method_label],
        ...(payment.reference_no ? [['Reference', payment.reference_no] as [string, string]] : []),
    ];

    return (
        <div className="bg-muted/40 min-h-svh py-6 print:bg-white print:py-0">
            <Head title={`Payment voucher ${payment.payment_no}`} />

            <div className="mx-auto mb-4 flex w-full max-w-[148mm] flex-wrap justify-center gap-2 px-2 print:hidden">
                <Button onClick={() => window.print()}>
                    <Printer className="size-4" /> Print
                </Button>
                <Button variant="outline" asChild>
                    <Link href={route('supplier-payments.show', payment.id)}>Back to payment</Link>
                </Button>
            </div>

            <article className="mx-auto w-full max-w-[148mm] bg-white p-8 text-[13px] leading-snug text-black shadow print:max-w-none print:p-0 print:shadow-none">
                <header className="text-center">
                    {shop.logo_url && <img src={shop.logo_url} alt="" className="mx-auto mb-1 max-h-16 max-w-[60mm] object-contain" />}
                    <h1 className="text-lg font-bold">{shop.name}</h1>
                    {shop.address && <p className="whitespace-pre-line">{shop.address}</p>}
                    {shop.phone && <p>Tel: {shop.phone}</p>}
                    <p className="mt-3 inline-block border border-black px-3 py-0.5 font-bold tracking-wide">
                        {outgoing ? 'PAYMENT VOUCHER' : 'MONEY RECEIPT'}
                    </p>
                </header>

                <div className="mt-5 grid grid-cols-2 gap-x-6 gap-y-1">
                    {rows.map(([label, value]) => (
                        <div key={label} className="flex justify-between gap-2 border-b border-dotted border-gray-400">
                            <span>{label}</span>
                            <span className="text-right font-medium">{value}</span>
                        </div>
                    ))}
                </div>

                <div className="mt-4">
                    <span>{outgoing ? 'Paid to' : 'Received from'}: </span>
                    <span className="font-bold">{payment.party?.name ?? 'Walk-in customer'}</span>
                    {payment.party?.phone && <span> ({payment.party.phone})</span>}
                </div>

                <div className="mt-3 flex items-center justify-between border-y-2 border-black py-2 text-base font-bold">
                    <span>Amount</span>
                    <span className="tabular-nums">{formatMoney(payment.amount)}</span>
                </div>

                {(payment.allocations?.length ?? 0) > 0 && (
                    <table className="mt-4 w-full">
                        <thead>
                            <tr className="border-b border-black text-left">
                                <th className="py-1 font-bold">Applied to</th>
                                <th className="py-1 text-right font-bold">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            {payment.allocations?.map((allocation) => (
                                <tr key={allocation.id} className="border-b border-dotted border-gray-400">
                                    <td className="py-1 font-mono">{allocation.document?.number ?? '—'}</td>
                                    <td className="py-1 text-right tabular-nums">{formatMoney(allocation.amount)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}

                {toCents(payment.unallocated_amount) > 0 && (
                    <p className="mt-2 flex justify-between">
                        <span>{outgoing ? 'Held as advance / on account' : 'On account'}</span>
                        <span className="tabular-nums">{formatMoney(payment.unallocated_amount)}</span>
                    </p>
                )}

                {payment.notes && <p className="mt-4">Notes: {payment.notes}</p>}
                {payment.created_by && <p className="mt-2 text-xs">Recorded by {payment.created_by}</p>}

                <div className="mt-16 grid grid-cols-3 gap-6 text-center text-xs">
                    <div className="border-t border-black pt-1">Prepared by</div>
                    <div className="border-t border-black pt-1">{outgoing ? 'Received by' : 'Paid by'}</div>
                    <div className="border-t border-black pt-1">Authorised by</div>
                </div>
            </article>
        </div>
    );
}
