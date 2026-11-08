import { type Sale } from '@/features/sales/types';
import { formatMoney, fromCents, toCents } from '@/lib/format';

/**
 * Totals and payment summary shared by the sale detail page and the printed slip.
 */
export function SaleTotals({ sale }: { sale: Sale }) {
    const serviceCents = (sale.service_charges ?? []).reduce((sum, charge) => sum + toCents(charge.amount), 0);
    const rows: [string, string, boolean?][] =
        serviceCents > 0
            ? [
                  ['Products', formatMoney(fromCents(toCents(sale.subtotal) - serviceCents))],
                  ['Service charges', formatMoney(fromCents(serviceCents))],
                  ['Subtotal', formatMoney(sale.subtotal)],
              ]
            : [['Subtotal', formatMoney(sale.subtotal)]];

    if (toCents(sale.items_discount) > 0) rows.push(['Item discounts', `−${formatMoney(sale.items_discount)}`]);
    if (toCents(sale.discount) > 0) rows.push(['Invoice discount', `−${formatMoney(sale.discount)}`]);
    rows.push(['Total', formatMoney(sale.total), true]);
    rows.push([`Paid${sale.payment_method_label ? ` (${sale.payment_method_label})` : ''}`, formatMoney(sale.paid_amount)]);
    if (sale.tendered_amount) rows.push(['Cash tendered', formatMoney(sale.tendered_amount)]);
    if (toCents(sale.change_amount) > 0) rows.push(['Change', formatMoney(sale.change_amount)]);
    if (toCents(sale.returned_amount) > 0) rows.push(['Returned', `−${formatMoney(sale.returned_amount)}`]);
    rows.push(['Due', formatMoney(sale.due_amount), true]);

    return (
        <dl className="space-y-1 text-sm">
            {rows.map(([label, value, strong]) => (
                <div key={label} className={strong ? 'flex justify-between border-t pt-1 font-semibold' : 'flex justify-between'}>
                    <dt>{label}</dt>
                    <dd className="tabular-nums">{value}</dd>
                </div>
            ))}
        </dl>
    );
}
