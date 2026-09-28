import { Pagination } from '@/components/pagination';
import { ReportFilters } from '@/features/reports/report-filters';
import { ReportPage, td, tdRight, th, thRight } from '@/features/reports/report-page';
import { StatCard } from '@/features/reports/stat-card';
import { formatDateTime, formatMoney } from '@/lib/format';
import { type Paginator } from '@/types';
import { Link } from '@inertiajs/react';

interface Totals {
    invoices: number;
    quantity: number;
    subtotal: string;
    discount: string;
    total: string;
    paid: string;
    due: string;
}

interface SalesReportProps {
    filters: { from: string; to: string; group_by: string };
    totals: Totals;
    periods: (Totals & { period: string })[];
    invoices: Paginator<{
        id: number;
        sold_at: string;
        invoice_no: string;
        sale_type: string;
        customer: string | null;
        quantity: number;
        subtotal: string;
        discount: string;
        total: string;
        paid: string;
        due: string;
    }>;
}

/**
 * T040 — POS sales by day or month (quantity and amount) and the invoice list.
 */
export default function SalesReport({ filters, totals, periods, invoices }: SalesReportProps) {
    const range = { from: filters.from, to: filters.to };

    return (
        <ReportPage title="Sales report" description="Completed POS sales" routeName="reports.sales" range={range}>
            <ReportFilters routeName="reports.sales" filters={filters} grouping />

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard label="Sales" value={formatMoney(totals.total)} sub={`${totals.invoices} invoice(s)`} />
                <StatCard label="Quantity" value={totals.quantity} sub="Units sold" />
                <StatCard label="Discounts" value={formatMoney(totals.discount)} sub={`on ${formatMoney(totals.subtotal)} subtotal`} />
                <StatCard label="Due (now)" value={formatMoney(totals.due)} sub={`Paid ${formatMoney(totals.paid)}`} />
            </div>

            <section className="space-y-2">
                <h3 className="font-medium">By {filters.group_by === 'month' ? 'month' : 'day'}</h3>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className={th}>{filters.group_by === 'month' ? 'Month' : 'Date'}</th>
                                <th className={thRight}>Invoices</th>
                                <th className={thRight}>Quantity</th>
                                <th className={thRight}>Subtotal</th>
                                <th className={thRight}>Discount</th>
                                <th className={thRight}>Amount</th>
                                <th className={thRight}>Paid</th>
                                <th className={thRight}>Due</th>
                            </tr>
                        </thead>
                        <tbody>
                            {periods.length === 0 && (
                                <tr>
                                    <td colSpan={8} className="text-muted-foreground px-3 py-6 text-center">
                                        No sales in this period.
                                    </td>
                                </tr>
                            )}
                            {periods.map((row) => (
                                <tr key={row.period} className="border-t">
                                    <td className={`${td} tabular-nums`}>{row.period}</td>
                                    <td className={tdRight}>{row.invoices}</td>
                                    <td className={tdRight}>{row.quantity}</td>
                                    <td className={tdRight}>{formatMoney(row.subtotal)}</td>
                                    <td className={tdRight}>{formatMoney(row.discount)}</td>
                                    <td className={`${tdRight} font-medium`}>{formatMoney(row.total)}</td>
                                    <td className={tdRight}>{formatMoney(row.paid)}</td>
                                    <td className={tdRight}>{formatMoney(row.due)}</td>
                                </tr>
                            ))}
                        </tbody>
                        {periods.length > 0 && (
                            <tfoot>
                                <tr className="bg-muted/30 border-t font-semibold">
                                    <td className={td}>Total</td>
                                    <td className={tdRight}>{totals.invoices}</td>
                                    <td className={tdRight}>{totals.quantity}</td>
                                    <td className={tdRight}>{formatMoney(totals.subtotal)}</td>
                                    <td className={tdRight}>{formatMoney(totals.discount)}</td>
                                    <td className={tdRight}>{formatMoney(totals.total)}</td>
                                    <td className={tdRight}>{formatMoney(totals.paid)}</td>
                                    <td className={tdRight}>{formatMoney(totals.due)}</td>
                                </tr>
                            </tfoot>
                        )}
                    </table>
                </div>
            </section>

            <section className="space-y-2">
                <h3 className="font-medium">Invoices</h3>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className={th}>Date / time</th>
                                <th className={th}>Invoice</th>
                                <th className={th}>Type</th>
                                <th className={th}>Customer</th>
                                <th className={thRight}>Qty</th>
                                <th className={thRight}>Subtotal</th>
                                <th className={thRight}>Discount</th>
                                <th className={thRight}>Total</th>
                                <th className={thRight}>Paid</th>
                                <th className={thRight}>Due</th>
                            </tr>
                        </thead>
                        <tbody>
                            {invoices.data.length === 0 && (
                                <tr>
                                    <td colSpan={10} className="text-muted-foreground px-3 py-6 text-center">
                                        No invoices.
                                    </td>
                                </tr>
                            )}
                            {invoices.data.map((sale) => (
                                <tr key={sale.id} className="border-t">
                                    <td className={`${td} whitespace-nowrap`}>{formatDateTime(sale.sold_at)}</td>
                                    <td className={td}>
                                        <Link href={route('sales.show', sale.id)} className="font-mono hover:underline">
                                            {sale.invoice_no}
                                        </Link>
                                    </td>
                                    <td className={td}>{sale.sale_type}</td>
                                    <td className={td}>{sale.customer ?? 'Walk-in'}</td>
                                    <td className={tdRight}>{sale.quantity}</td>
                                    <td className={tdRight}>{formatMoney(sale.subtotal)}</td>
                                    <td className={tdRight}>{formatMoney(sale.discount)}</td>
                                    <td className={`${tdRight} font-medium`}>{formatMoney(sale.total)}</td>
                                    <td className={tdRight}>{formatMoney(sale.paid)}</td>
                                    <td className={tdRight}>{formatMoney(sale.due)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination meta={invoices} />
            </section>
        </ReportPage>
    );
}
