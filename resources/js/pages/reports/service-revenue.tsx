import { Pagination } from '@/components/pagination';
import { ReportFilters } from '@/features/reports/report-filters';
import { ReportPage, td, tdRight, th, thRight } from '@/features/reports/report-page';
import { StatCard } from '@/features/reports/stat-card';
import { type RevenuePeriod } from '@/features/reports/types';
import { formatDateTime, formatMoney } from '@/lib/format';
import { type Paginator } from '@/types';
import { Link } from '@inertiajs/react';

interface ServiceRevenueProps {
    filters: { from: string; to: string; group_by: string };
    totals: { revenue: string; invoices: number; repairs: string; pos_charges: string; pos_sales: number };
    periods: RevenuePeriod[];
    technicians: { source: 'JOB' | 'POS'; technician_id: number | null; technician: string | null; invoices: number; revenue: string }[];
    lines: Paginator<{
        source: 'JOB' | 'POS';
        id: number;
        description: string;
        amount: string;
        invoice_id: number;
        invoice_no: string;
        invoiced_at: string;
        job_id: number | null;
        job_no: string | null;
        customer: string;
        technician: string | null;
    }>;
}

/**
 * T042 — mobile service revenue from SERVICE lines only (parts on the same invoices are product revenue),
 * including service charges billed on POS sales.
 */
export default function ServiceRevenue({ filters, totals, periods, technicians, lines }: ServiceRevenueProps) {
    const range = { from: filters.from, to: filters.to };
    const withService = periods.filter((row) => row.service !== '0.00');

    return (
        <ReportPage title="Mobile service revenue" description="SERVICE (labour) lines only" routeName="reports.service-revenue" range={range}>
            <ReportFilters routeName="reports.service-revenue" filters={filters} grouping />

            <div className="grid gap-4 sm:grid-cols-3">
                <StatCard
                    label="Service revenue"
                    value={formatMoney(totals.revenue)}
                    sub={`Repairs ${formatMoney(totals.repairs)} · POS service charges ${formatMoney(totals.pos_charges)}`}
                    emphasis
                />
                <StatCard
                    label="Documents"
                    value={`${totals.invoices + totals.pos_sales}`}
                    sub={`${totals.invoices} service invoice(s) · ${totals.pos_sales} POS sale(s)`}
                />
                <StatCard
                    label="Parts on service invoices"
                    value="Product revenue"
                    sub="Counted in the product revenue report"
                    href={route('reports.product-revenue', range)}
                />
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
                <section className="space-y-2">
                    <h3 className="font-medium">By {filters.group_by === 'month' ? 'month' : 'day'}</h3>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className={th}>{filters.group_by === 'month' ? 'Month' : 'Date'}</th>
                                    <th className={thRight}>Service revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                {withService.length === 0 && (
                                    <tr>
                                        <td colSpan={2} className="text-muted-foreground px-3 py-6 text-center">
                                            No service revenue in this period.
                                        </td>
                                    </tr>
                                )}
                                {withService.map((row) => (
                                    <tr key={row.period} className="border-t">
                                        <td className={`${td} tabular-nums`}>{row.period}</td>
                                        <td className={tdRight}>{formatMoney(row.service)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="space-y-2">
                    <h3 className="font-medium">By technician</h3>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className={th}>Technician</th>
                                    <th className={thRight}>Invoices</th>
                                    <th className={thRight}>Service revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                {technicians.length === 0 && (
                                    <tr>
                                        <td colSpan={3} className="text-muted-foreground px-3 py-6 text-center">
                                            —
                                        </td>
                                    </tr>
                                )}
                                {technicians.map((row) => (
                                    <tr key={`${row.source}-${row.technician_id ?? 'none'}`} className="border-t">
                                        <td className={td}>{row.technician ?? <span className="text-muted-foreground">Unassigned</span>}</td>
                                        <td className={tdRight}>{row.invoices}</td>
                                        <td className={tdRight}>{formatMoney(row.revenue)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <section className="space-y-2">
                <h3 className="font-medium">Service lines</h3>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className={th}>Date</th>
                                <th className={th}>Invoice</th>
                                <th className={th}>Job</th>
                                <th className={th}>Customer</th>
                                <th className={th}>Technician</th>
                                <th className={th}>Service</th>
                                <th className={thRight}>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            {lines.data.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="text-muted-foreground px-3 py-6 text-center">
                                        No service lines.
                                    </td>
                                </tr>
                            )}
                            {lines.data.map((line) => (
                                <tr key={`${line.source}-${line.id}`} className="border-t">
                                    <td className={`${td} whitespace-nowrap`}>{formatDateTime(line.invoiced_at)}</td>
                                    <td className={td}>
                                        <Link
                                            href={
                                                line.source === 'POS'
                                                    ? route('sales.show', line.invoice_id)
                                                    : route('service-invoices.show', line.invoice_id)
                                            }
                                            className="font-mono whitespace-nowrap hover:underline"
                                        >
                                            {line.invoice_no}
                                        </Link>
                                    </td>
                                    <td className={td}>
                                        {line.job_id ? (
                                            <Link
                                                href={route('service-jobs.show', line.job_id)}
                                                className="font-mono whitespace-nowrap hover:underline"
                                            >
                                                {line.job_no}
                                            </Link>
                                        ) : (
                                            <span className="text-muted-foreground">POS sale</span>
                                        )}
                                    </td>
                                    <td className={td}>{line.customer}</td>
                                    <td className={td}>{line.technician ?? '—'}</td>
                                    <td className={td}>{line.description}</td>
                                    <td className={tdRight}>{formatMoney(line.amount)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination meta={lines} />
            </section>
        </ReportPage>
    );
}
