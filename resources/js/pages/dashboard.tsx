import Heading from '@/components/heading';
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ReportFilters } from '@/features/reports/report-filters';
import { StatCard } from '@/features/reports/stat-card';
import { type RevenuePeriod } from '@/features/reports/types';
import AppLayout from '@/layouts/app-layout';
import { formatMoney, toCents } from '@/lib/format';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

/** Sections are present only when the user may see them (server-side ReportAccess). */
interface Summary {
    product_sales?: { amount: string; quantity: number };
    gross_profit?: string;
    service_revenue?: string;
    combined_revenue?: string;
    documents?: { sales: number; service_invoices: number; sale_returns: number; total: string; matches: boolean };
    trend?: RevenuePeriod[];
    purchases?: { amount: string; documents: number; returns: string };
    expenses?: { amount: string; entries: number };
    outstanding?: { receivable: string; receivable_parties: number; payable: string; payable_parties: number };
    low_stock?: { count: number; products: { id: number; name: string; sku: string; stock: number; reorder_level: number }[] };
}

interface DashboardProps {
    summary: Summary | null;
    filters: { from: string; to: string; group_by: string } | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

// Categorical slots 1–2 of the reference palette, validated (CVD, contrast) for light and dark surfaces.
const PRODUCT_FILL = 'bg-[#2a78d6] dark:bg-[#3987e5]';
const SERVICE_FILL = 'bg-[#eb6834] dark:bg-[#d95926]';

/**
 * Stacked product / service revenue per day or month. Values are exact money strings; the bars are
 * proportional. Hover a row for its figures; the combined revenue report is the table view.
 */
function RevenueTrend({ rows, tableHref }: { rows: RevenuePeriod[]; tableHref: string }) {
    const max = Math.max(1, ...rows.map((row) => toCents(row.combined)));

    if (rows.length === 0) {
        return <p className="text-muted-foreground py-6 text-center text-sm">No revenue in this period.</p>;
    }

    return (
        <div className="space-y-1">
            <div className="text-muted-foreground flex flex-wrap items-center gap-4 pb-1 text-xs">
                <span className="flex items-center gap-1">
                    <span className={`inline-block size-2.5 rounded-sm ${PRODUCT_FILL}`} /> Product
                </span>
                <span className="flex items-center gap-1">
                    <span className={`inline-block size-2.5 rounded-sm ${SERVICE_FILL}`} /> Service
                </span>
                <Link href={tableHref} className="ml-auto hover:underline">
                    View as table →
                </Link>
            </div>
            {rows.map((row) => {
                const product = Math.max(0, toCents(row.product));
                const service = Math.max(0, toCents(row.service));

                return (
                    <div
                        key={row.period}
                        className="group hover:bg-muted/50 relative grid grid-cols-[80px_minmax(0,1fr)_100px] items-center gap-2 rounded px-1 py-1 text-xs"
                    >
                        <span className="text-muted-foreground tabular-nums">{row.period}</span>
                        <div className="flex h-2.5 gap-[2px]" aria-hidden>
                            {product > 0 && (
                                <div
                                    className={`rounded-l-[4px] ${service > 0 ? '' : 'rounded-r-[4px]'} ${PRODUCT_FILL}`}
                                    style={{ width: `${(product / max) * 100}%` }}
                                />
                            )}
                            {service > 0 && (
                                <div
                                    className={`rounded-r-[4px] ${product > 0 ? '' : 'rounded-l-[4px]'} ${SERVICE_FILL}`}
                                    style={{ width: `${(service / max) * 100}%` }}
                                />
                            )}
                        </div>
                        <span className="text-right tabular-nums">{formatMoney(row.combined)}</span>
                        <div
                            role="tooltip"
                            className="bg-popover text-popover-foreground pointer-events-none absolute top-full left-24 z-10 hidden min-w-44 rounded-md border px-3 py-2 shadow-md group-hover:block"
                        >
                            <div className="mb-1 font-medium">{row.period}</div>
                            <div className="flex justify-between gap-4">
                                <span>Product</span>
                                <span className="tabular-nums">{formatMoney(row.product)}</span>
                            </div>
                            <div className="flex justify-between gap-4">
                                <span>Service</span>
                                <span className="tabular-nums">{formatMoney(row.service)}</span>
                            </div>
                            <div className="flex justify-between gap-4 border-t pt-1 font-medium">
                                <span>Combined</span>
                                <span className="tabular-nums">{formatMoney(row.combined)}</span>
                            </div>
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

export default function Dashboard({ summary, filters }: DashboardProps) {
    const { auth } = usePage<SharedData>().props;

    if (!summary || !filters) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Dashboard" />
                <div className="flex h-full flex-1 flex-col gap-4 p-4 md:p-6">
                    <Heading title={`Welcome, ${auth.user.name}`} description="Use the navigation to access the modules available to your role." />
                    <Card className="max-w-md">
                        <CardHeader>
                            <CardTitle className="text-base">Your access</CardTitle>
                            <CardDescription>
                                Role: {auth.user.roles.join(', ') || 'None assigned'} · {auth.user.permissions.length} permission(s)
                            </CardDescription>
                        </CardHeader>
                    </Card>
                </div>
            </AppLayout>
        );
    }

    const range = { from: filters.from, to: filters.to };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <Heading title="Dashboard" description={filters.from === filters.to ? filters.from : `${filters.from} → ${filters.to}`} />
                    <ReportFilters routeName="dashboard" filters={filters} />
                </div>

                <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {summary.product_sales && (
                        <StatCard
                            label="Product sales"
                            value={formatMoney(summary.product_sales.amount)}
                            sub={`${summary.product_sales.quantity} unit(s) · POS + service parts − returns`}
                            href={route('reports.product-revenue', range)}
                        />
                    )}
                    {summary.service_revenue !== undefined && (
                        <StatCard
                            label="Mobile service revenue"
                            value={formatMoney(summary.service_revenue)}
                            sub="Service / labour lines"
                            href={route('reports.service-revenue', range)}
                        />
                    )}
                    {summary.combined_revenue !== undefined && summary.documents && (
                        <StatCard
                            label="Combined revenue"
                            value={formatMoney(summary.combined_revenue)}
                            sub={`${summary.documents.sales} sale(s), ${summary.documents.service_invoices} service invoice(s)`}
                            href={route('reports.revenue', range)}
                            emphasis
                        />
                    )}
                    {summary.gross_profit !== undefined && (
                        <StatCard label="Product gross profit" value={formatMoney(summary.gross_profit)} sub="Product sales − cost of goods" />
                    )}
                    {summary.purchases && (
                        <StatCard
                            label="Purchases"
                            value={formatMoney(summary.purchases.amount)}
                            sub={`${summary.purchases.documents} purchase(s)${toCents(summary.purchases.returns) > 0 ? `, returns −${formatMoney(summary.purchases.returns)}` : ''}`}
                            href={route('purchases.index')}
                        />
                    )}
                    {summary.expenses && (
                        <StatCard
                            label="Expenses"
                            value={formatMoney(summary.expenses.amount)}
                            sub={`${summary.expenses.entries} entr(ies)`}
                            href={route('expenses.report', range)}
                        />
                    )}
                    {summary.outstanding && (
                        <>
                            <StatCard
                                label="Receivables (now)"
                                value={formatMoney(summary.outstanding.receivable)}
                                sub={`${summary.outstanding.receivable_parties} part(ies) owe the shop`}
                                href={route('reports.parties', { ...range, side: 'receivable' })}
                            />
                            <StatCard
                                label="Supplier payables (now)"
                                value={formatMoney(summary.outstanding.payable)}
                                sub={`The shop owes ${summary.outstanding.payable_parties} part(ies)`}
                                href={route('reports.parties', { ...range, side: 'payable' })}
                            />
                        </>
                    )}
                </section>

                {(summary.trend || summary.low_stock) && (
                    <section className={summary.trend && summary.low_stock ? 'grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]' : 'grid gap-6'}>
                        {summary.trend && (
                            <div className="space-y-3 rounded-lg border p-4">
                                <h3 className="font-medium">Revenue {filters.group_by === 'month' ? 'by month' : 'by day'}</h3>
                                <RevenueTrend rows={summary.trend} tableHref={route('reports.revenue', { ...range, group_by: filters.group_by })} />
                            </div>
                        )}

                        {summary.low_stock && (
                            <div className="rounded-lg border lg:max-w-xl">
                                <div className="flex items-center justify-between border-b px-4 py-2">
                                    <h3 className="text-sm font-medium">Low stock</h3>
                                    <Link href={route('inventory.low-stock.index')} className="text-muted-foreground text-xs hover:underline">
                                        {summary.low_stock.count} product(s) →
                                    </Link>
                                </div>
                                {summary.low_stock.products.length === 0 && (
                                    <p className="text-muted-foreground px-4 py-3 text-sm">Nothing below its reorder level.</p>
                                )}
                                <ul className="divide-y text-sm">
                                    {summary.low_stock.products.map((product) => (
                                        <li key={product.id} className="flex items-center justify-between gap-2 px-4 py-2">
                                            <Link href={route('products.show', product.id)} className="min-w-0 truncate hover:underline">
                                                {product.name}
                                            </Link>
                                            <span className={product.stock <= 0 ? 'text-destructive tabular-nums' : 'tabular-nums'}>
                                                {product.stock} / {product.reorder_level}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </section>
                )}

                {summary.documents && !summary.documents.matches && (
                    <p className="text-destructive text-sm">Revenue lines and document totals differ — run the reconciliation checks.</p>
                )}
            </div>
        </AppLayout>
    );
}
