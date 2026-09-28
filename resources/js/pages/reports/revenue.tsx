import { ReportFilters } from '@/features/reports/report-filters';
import { ReportPage, td, tdRight, th, thRight } from '@/features/reports/report-page';
import { StatCard } from '@/features/reports/stat-card';
import { deduction, type RevenuePeriod } from '@/features/reports/types';
import { formatMoney } from '@/lib/format';

interface CombinedRevenueProps {
    filters: { from: string; to: string; group_by: string };
    totals: {
        product: string;
        service: string;
        combined: string;
        product_breakdown: { pos_sales: string; service_parts: string; returns: string };
        documents: { sales: number; service_invoices: number; sale_returns: number; total: string; matches: boolean };
    };
    periods: RevenuePeriod[];
}

/**
 * T043 — combined revenue = PRODUCT revenue + SERVICE revenue, from invoice lines.
 */
export default function CombinedRevenue({ filters, totals, periods }: CombinedRevenueProps) {
    const range = { from: filters.from, to: filters.to };

    return (
        <ReportPage title="Combined revenue" description="Product revenue + mobile service revenue" routeName="reports.revenue" range={range}>
            <ReportFilters routeName="reports.revenue" filters={filters} grouping />

            <div className="grid gap-4 sm:grid-cols-3">
                <StatCard
                    label="Product revenue"
                    value={formatMoney(totals.product)}
                    sub={`POS ${formatMoney(totals.product_breakdown.pos_sales)} + parts ${formatMoney(totals.product_breakdown.service_parts)} − returns ${formatMoney(totals.product_breakdown.returns)}`}
                    href={route('reports.product-revenue', range)}
                />
                <StatCard
                    label="Service revenue"
                    value={formatMoney(totals.service)}
                    sub="Service / labour lines"
                    href={route('reports.service-revenue', range)}
                />
                <StatCard label="Combined revenue" value={formatMoney(totals.combined)} sub="Product + service" emphasis />
            </div>

            <p className={totals.documents.matches ? 'text-muted-foreground text-xs' : 'text-destructive text-sm font-medium'}>
                Check against document totals ({totals.documents.sales} sale(s) + {totals.documents.service_invoices} service invoice(s) −{' '}
                {totals.documents.sale_returns} return(s)): {formatMoney(totals.documents.total)} —{' '}
                {totals.documents.matches ? 'matches; no invoice is counted twice.' : 'does NOT match the line totals.'}
            </p>

            <div className="overflow-x-auto rounded-lg border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            <th className={th}>{filters.group_by === 'month' ? 'Month' : 'Date'}</th>
                            <th className={thRight}>POS sales</th>
                            <th className={thRight}>Service parts</th>
                            <th className={thRight}>Returns</th>
                            <th className={thRight}>Product revenue</th>
                            <th className={thRight}>Service revenue</th>
                            <th className={thRight}>Combined</th>
                        </tr>
                    </thead>
                    <tbody>
                        {periods.length === 0 && (
                            <tr>
                                <td colSpan={7} className="text-muted-foreground px-3 py-6 text-center">
                                    No revenue in this period.
                                </td>
                            </tr>
                        )}
                        {periods.map((row) => (
                            <tr key={row.period} className="border-t">
                                <td className={`${td} tabular-nums`}>{row.period}</td>
                                <td className={tdRight}>{formatMoney(row.pos_sales)}</td>
                                <td className={tdRight}>{formatMoney(row.service_parts)}</td>
                                <td className={tdRight}>{deduction(row.returns, formatMoney)}</td>
                                <td className={tdRight}>{formatMoney(row.product)}</td>
                                <td className={tdRight}>{formatMoney(row.service)}</td>
                                <td className={`${tdRight} font-medium`}>{formatMoney(row.combined)}</td>
                            </tr>
                        ))}
                    </tbody>
                    {periods.length > 0 && (
                        <tfoot>
                            <tr className="bg-muted/30 border-t font-semibold">
                                <td className={td}>Total</td>
                                <td className={tdRight}>{formatMoney(totals.product_breakdown.pos_sales)}</td>
                                <td className={tdRight}>{formatMoney(totals.product_breakdown.service_parts)}</td>
                                <td className={tdRight}>{deduction(totals.product_breakdown.returns, formatMoney)}</td>
                                <td className={tdRight}>{formatMoney(totals.product)}</td>
                                <td className={tdRight}>{formatMoney(totals.service)}</td>
                                <td className={tdRight}>{formatMoney(totals.combined)}</td>
                            </tr>
                        </tfoot>
                    )}
                </table>
            </div>
        </ReportPage>
    );
}
