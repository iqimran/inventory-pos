import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { ReportFilters } from '@/features/reports/report-filters';
import { ReportPage, td, tdRight, th, thRight } from '@/features/reports/report-page';
import { StatCard } from '@/features/reports/stat-card';
import { formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type Option, type Paginator } from '@/types';
import { Link } from '@inertiajs/react';

interface StockReportProps {
    filters: { from: string; to: string; group_by: string; q: string; category_id: string | number; status: string };
    totals: { products: number; units: number; value?: string; low: number; out: number };
    products: Paginator<{
        id: number;
        name: string;
        sku: string;
        category: string | null;
        unit: string | null;
        quantity: number;
        reorder_level: number;
        is_low: boolean;
        unit_cost?: string;
        value?: string;
        retail_price: string;
    }>;
    movementTypes: { type: string; label: string; movements: number; quantity: number }[];
    movements: Paginator<{ product_id: number; name: string; sku: string; opening: number; in: number; out: number; closing: number }>;
    categories: Option[];
    showCost: boolean;
}

const selectClass = 'border-input bg-background h-9 rounded-md border px-3 text-sm';

/**
 * T044 — current stock and value, and stock movements in the period (all from the stock ledger).
 */
export default function StockReport({ filters, totals, products, movementTypes, movements, categories, showCost }: StockReportProps) {
    const range = { from: filters.from, to: filters.to };

    return (
        <ReportPage title="Stock report" description="Current stock and movements from the stock ledger" routeName="reports.stock" range={range}>
            <ReportFilters routeName="reports.stock" filters={{ ...filters, category_id: String(filters.category_id ?? '') }}>
                {(values, set) => (
                    <>
                        <Input
                            value={String(values.q ?? '')}
                            onChange={(e) => set('q', e.target.value)}
                            placeholder="Name, SKU or barcode"
                            className="sm:w-52"
                            aria-label="Search products"
                        />
                        <select
                            className={selectClass}
                            value={String(values.category_id ?? '')}
                            onChange={(e) => set('category_id', e.target.value)}
                            aria-label="Category"
                        >
                            <option value="">All categories</option>
                            {categories.map((category) => (
                                <option key={category.id} value={category.id}>
                                    {category.name}
                                </option>
                            ))}
                        </select>
                        <select
                            className={selectClass}
                            value={String(values.status ?? '')}
                            onChange={(e) => set('status', e.target.value)}
                            aria-label="Stock status"
                        >
                            <option value="">All stock levels</option>
                            <option value="in_stock">In stock</option>
                            <option value="low">Low stock</option>
                            <option value="out">Out of stock</option>
                        </select>
                    </>
                )}
            </ReportFilters>

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard label="Units in stock" value={totals.units} sub={`${totals.products} active product(s)`} />
                {showCost && totals.value !== undefined && <StatCard label="Stock value" value={formatMoney(totals.value)} sub="At average cost" />}
                <StatCard label="Low stock" value={totals.low} sub="At or below reorder level" href={route('inventory.low-stock.index')} />
                <StatCard label="Out of stock" value={totals.out} />
            </div>

            <section className="space-y-2">
                <h3 className="font-medium">Current stock</h3>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className={th}>Product</th>
                                <th className={th}>Category</th>
                                <th className={thRight}>Stock</th>
                                <th className={thRight}>Reorder level</th>
                                {showCost && <th className={thRight}>Unit cost</th>}
                                {showCost && <th className={thRight}>Value</th>}
                                <th className={thRight}>Retail price</th>
                            </tr>
                        </thead>
                        <tbody>
                            {products.data.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="text-muted-foreground px-3 py-6 text-center">
                                        No products match.
                                    </td>
                                </tr>
                            )}
                            {products.data.map((product) => (
                                <tr key={product.id} className="border-t">
                                    <td className={td}>
                                        <Link href={route('products.show', product.id)} className="font-medium hover:underline">
                                            {product.name}
                                        </Link>
                                        <div className="text-muted-foreground font-mono text-xs">{product.sku}</div>
                                    </td>
                                    <td className={td}>{product.category ?? '—'}</td>
                                    <td className={cn(tdRight, product.quantity <= 0 && 'text-destructive')}>
                                        {product.quantity} {product.unit}
                                        {product.is_low && (
                                            <Badge variant="outline" className="ml-2">
                                                Low
                                            </Badge>
                                        )}
                                    </td>
                                    <td className={tdRight}>{product.reorder_level}</td>
                                    {showCost && <td className={tdRight}>{formatMoney(product.unit_cost)}</td>}
                                    {showCost && <td className={tdRight}>{formatMoney(product.value)}</td>}
                                    <td className={tdRight}>{formatMoney(product.retail_price)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination meta={products} />
            </section>

            <section className="space-y-2">
                <div className="flex items-center justify-between">
                    <h3 className="font-medium">Movements in the period</h3>
                    <Link href={route('inventory.movements.index')} className="text-muted-foreground text-sm hover:underline">
                        Full movement history →
                    </Link>
                </div>
                <div className="flex flex-wrap gap-2">
                    {movementTypes.length === 0 && <p className="text-muted-foreground text-sm">No movements in this period.</p>}
                    {movementTypes.map((type) => (
                        <div key={type.type} className="rounded-md border px-3 py-1.5 text-sm">
                            <span className="text-muted-foreground">{type.label}</span>{' '}
                            <span className="font-medium tabular-nums">{type.quantity > 0 ? `+${type.quantity}` : type.quantity}</span>
                            <span className="text-muted-foreground text-xs"> ({type.movements})</span>
                        </div>
                    ))}
                </div>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className={th}>Product</th>
                                <th className={thRight}>Opening</th>
                                <th className={thRight}>In</th>
                                <th className={thRight}>Out</th>
                                <th className={thRight}>Closing</th>
                            </tr>
                        </thead>
                        <tbody>
                            {movements.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="text-muted-foreground px-3 py-6 text-center">
                                        No stock history up to this period.
                                    </td>
                                </tr>
                            )}
                            {movements.data.map((row) => (
                                <tr key={row.product_id} className="border-t">
                                    <td className={td}>
                                        {row.name}
                                        <div className="text-muted-foreground font-mono text-xs">{row.sku}</div>
                                    </td>
                                    <td className={tdRight}>{row.opening}</td>
                                    <td className={cn(tdRight, 'text-green-700 dark:text-green-400')}>{row.in > 0 ? `+${row.in}` : '—'}</td>
                                    <td className={cn(tdRight, 'text-destructive')}>{row.out > 0 ? `−${row.out}` : '—'}</td>
                                    <td className={`${tdRight} font-medium`}>{row.closing}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination meta={movements} />
            </section>
        </ReportPage>
    );
}
