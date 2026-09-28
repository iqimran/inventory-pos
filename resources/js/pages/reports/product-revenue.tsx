import { Pagination } from '@/components/pagination';
import { ReportFilters } from '@/features/reports/report-filters';
import { ReportPage, td, tdRight, th, thRight } from '@/features/reports/report-page';
import { StatCard } from '@/features/reports/stat-card';
import { deduction, type RevenuePeriod } from '@/features/reports/types';
import { formatMoney } from '@/lib/format';
import { type Paginator } from '@/types';
import { Link } from '@inertiajs/react';

interface ProductRevenueProps {
    filters: { from: string; to: string; group_by: string };
    totals: {
        pos_sales: string;
        service_parts: string;
        returns: string;
        net: string;
        quantity: { pos: number; parts: number; returned: number; net: number };
        cost?: string;
        gross_profit?: string;
    };
    periods: RevenuePeriod[];
    products: Paginator<{
        product_id: number;
        name: string;
        sku: string;
        pos_qty: number;
        pos_amount: string;
        parts_qty: number;
        parts_amount: string;
        returned_qty: number;
        returned_amount: string;
        net_qty: number;
        net_amount: string;
        cost?: string;
    }>;
    showCost: boolean;
}

/**
 * T041 — revenue from PRODUCT lines only: POS sale lines + service-invoice parts − sale returns.
 */
export default function ProductRevenue({ filters, totals, periods, products, showCost }: ProductRevenueProps) {
    const range = { from: filters.from, to: filters.to };

    return (
        <ReportPage
            title="Product revenue"
            description="PRODUCT lines: POS sales and service parts, net of returns"
            routeName="reports.product-revenue"
            range={range}
        >
            <ReportFilters routeName="reports.product-revenue" filters={filters} grouping />

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard label="Product revenue" value={formatMoney(totals.net)} sub={`${totals.quantity.net} unit(s) net`} emphasis />
                <StatCard label="POS sales" value={formatMoney(totals.pos_sales)} sub={`${totals.quantity.pos} unit(s)`} />
                <StatCard
                    label="Service parts"
                    value={formatMoney(totals.service_parts)}
                    sub={`${totals.quantity.parts} unit(s) on service invoices`}
                />
                <StatCard label="Returns" value={`−${formatMoney(totals.returns)}`} sub={`${totals.quantity.returned} unit(s) returned`} />
                {showCost && totals.cost !== undefined && totals.gross_profit !== undefined && (
                    <>
                        <StatCard label="Cost of goods" value={formatMoney(totals.cost)} sub="Cost snapshot at sale" />
                        <StatCard label="Gross profit" value={formatMoney(totals.gross_profit)} sub="Product revenue − cost" />
                    </>
                )}
            </div>

            <section className="space-y-2">
                <h3 className="font-medium">By {filters.group_by === 'month' ? 'month' : 'day'}</h3>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className={th}>{filters.group_by === 'month' ? 'Month' : 'Date'}</th>
                                <th className={thRight}>Quantity</th>
                                <th className={thRight}>POS sales</th>
                                <th className={thRight}>Service parts</th>
                                <th className={thRight}>Returns</th>
                                <th className={thRight}>Product revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            {periods.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground px-3 py-6 text-center">
                                        No product revenue in this period.
                                    </td>
                                </tr>
                            )}
                            {periods.map((row) => (
                                <tr key={row.period} className="border-t">
                                    <td className={`${td} tabular-nums`}>{row.period}</td>
                                    <td className={tdRight}>{row.product_quantity}</td>
                                    <td className={tdRight}>{formatMoney(row.pos_sales)}</td>
                                    <td className={tdRight}>{formatMoney(row.service_parts)}</td>
                                    <td className={tdRight}>{deduction(row.returns, formatMoney)}</td>
                                    <td className={`${tdRight} font-medium`}>{formatMoney(row.product)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>

            <section className="space-y-2">
                <h3 className="font-medium">By product</h3>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className={th}>Product</th>
                                <th className={thRight}>POS qty</th>
                                <th className={thRight}>POS amount</th>
                                <th className={thRight}>Parts qty</th>
                                <th className={thRight}>Parts amount</th>
                                <th className={thRight}>Returned</th>
                                <th className={thRight}>Net qty</th>
                                <th className={thRight}>Net revenue</th>
                                {showCost && <th className={thRight}>Cost</th>}
                            </tr>
                        </thead>
                        <tbody>
                            {products.data.length === 0 && (
                                <tr>
                                    <td colSpan={9} className="text-muted-foreground px-3 py-6 text-center">
                                        No products sold in this period.
                                    </td>
                                </tr>
                            )}
                            {products.data.map((row) => (
                                <tr key={row.product_id} className="border-t">
                                    <td className={td}>
                                        <Link href={route('products.show', row.product_id)} className="font-medium hover:underline">
                                            {row.name}
                                        </Link>
                                        <div className="text-muted-foreground font-mono text-xs">{row.sku}</div>
                                    </td>
                                    <td className={tdRight}>{row.pos_qty}</td>
                                    <td className={tdRight}>{formatMoney(row.pos_amount)}</td>
                                    <td className={tdRight}>{row.parts_qty}</td>
                                    <td className={tdRight}>{formatMoney(row.parts_amount)}</td>
                                    <td className={tdRight}>
                                        {row.returned_qty > 0 ? `${row.returned_qty} (−${formatMoney(row.returned_amount)})` : '—'}
                                    </td>
                                    <td className={tdRight}>{row.net_qty}</td>
                                    <td className={`${tdRight} font-medium`}>{formatMoney(row.net_amount)}</td>
                                    {showCost && <td className={tdRight}>{formatMoney(row.cost)}</td>}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination meta={products} />
            </section>
        </ReportPage>
    );
}
