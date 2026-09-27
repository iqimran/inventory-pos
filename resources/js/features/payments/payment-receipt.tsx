import { type Payment } from '@/features/purchasing/types';
import { formatDateTime, formatMoney, toCents } from '@/lib/format';
import { Link } from '@inertiajs/react';

function documentHref(document: { type: string; id: number }): string {
    return document.type === 'sale' ? route('sales.show', document.id) : route('purchases.show', document.id);
}

/**
 * Receipt card for a supplier or customer payment, with the documents it settled.
 */
export function PaymentReceipt({ payment }: { payment: Payment }) {
    const rows: [string, string][] = [
        ['Date', formatDateTime(payment.paid_at)],
        ['Type', payment.purpose_label],
        ['Method', payment.method_label],
        ['Reference', payment.reference_no ?? '—'],
        ['Recorded by', payment.created_by ?? '—'],
    ];
    const settles = payment.purpose === 'PURCHASE_PAYMENT' || payment.purpose === 'SUPPLIER_ADVANCE' || payment.purpose === 'SALE_PAYMENT';

    return (
        <div className="space-y-4 rounded-lg border p-6">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <p className="text-muted-foreground text-xs tracking-wide uppercase">
                        {payment.direction === 'IN' ? 'Money received' : 'Payment receipt'}
                    </p>
                    <h2 className="font-mono text-xl font-semibold">{payment.payment_no}</h2>
                    {payment.party ? (
                        <Link href={route('parties.show', payment.party.id)} className="text-sm hover:underline">
                            {payment.party.name}
                            {payment.party.phone && ` · ${payment.party.phone}`}
                        </Link>
                    ) : (
                        <p className="text-muted-foreground text-sm">Walk-in customer</p>
                    )}
                </div>
                <div className="text-right">
                    <div className="text-muted-foreground text-xs">Amount</div>
                    <div className="text-2xl font-semibold tabular-nums">{formatMoney(payment.amount)}</div>
                </div>
            </div>

            <dl className="grid grid-cols-2 gap-3 text-sm">
                {rows.map(([label, value]) => (
                    <div key={label}>
                        <dt className="text-muted-foreground">{label}</dt>
                        <dd className="font-medium">{value}</dd>
                    </div>
                ))}
            </dl>

            {settles && (
                <div className="space-y-2 border-t pt-4">
                    <h3 className="text-sm font-medium">Applied to</h3>
                    {payment.allocations?.length === 0 && <p className="text-muted-foreground text-sm">Not applied to any document.</p>}
                    <ul className="space-y-1 text-sm">
                        {payment.allocations?.map((allocation) => (
                            <li key={allocation.id} className="flex justify-between">
                                {allocation.document ? (
                                    <Link href={documentHref(allocation.document)} className="font-mono hover:underline">
                                        {allocation.document.number}
                                    </Link>
                                ) : (
                                    <span>—</span>
                                )}
                                <span className="tabular-nums">{formatMoney(allocation.amount)}</span>
                            </li>
                        ))}
                    </ul>
                    {toCents(payment.unallocated_amount) > 0 && (
                        <p className="text-muted-foreground flex justify-between text-sm">
                            <span>Not applied to a document (advance / opening balance)</span>
                            <span className="tabular-nums">{formatMoney(payment.unallocated_amount)}</span>
                        </p>
                    )}
                </div>
            )}

            {payment.notes && <p className="border-t pt-4 text-sm">Notes: {payment.notes}</p>}
        </div>
    );
}
