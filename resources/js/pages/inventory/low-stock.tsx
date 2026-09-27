import Heading from '@/components/heading';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StockBadge } from '@/features/products/stock-badge';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { type Option, type Paginator } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface LowStockRow {
    id: number;
    name: string;
    sku: string;
    barcode: string | null;
    category: string;
    subcategory: string | null;
    brand: string | null;
    unit: string;
    stock: number;
    reorder_level: number;
    shortfall: number;
}

interface LowStockProps {
    products: Paginator<LowStockRow>;
    filters: { q: string; category_id: number | null; only_out_of_stock: boolean };
    categories: Option[];
}

export default function LowStock({ products, filters, categories }: LowStockProps) {
    const can = useCan();
    const [form, setForm] = useState({
        q: filters.q,
        category_id: filters.category_id ? String(filters.category_id) : '',
        only_out_of_stock: filters.only_out_of_stock,
    });

    const apply: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(
            route('inventory.low-stock.index'),
            { q: form.q || undefined, category_id: form.category_id || undefined, only_out_of_stock: form.only_out_of_stock ? 1 : undefined },
            { preserveState: true, replace: true },
        );
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Low stock', href: route('inventory.low-stock.index') }]}>
            <Head title="Low stock" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading title="Low stock" description="Active products at or below their reorder level. Out-of-stock items are listed first." />

                <form onSubmit={apply} className="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <Input
                        placeholder="Name, SKU or barcode"
                        value={form.q}
                        onChange={(e) => setForm({ ...form, q: e.target.value })}
                        className="sm:max-w-xs"
                    />
                    <select
                        value={form.category_id}
                        onChange={(e) => setForm({ ...form, category_id: e.target.value })}
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        aria-label="Category"
                    >
                        <option value="">All categories</option>
                        {categories.map((category) => (
                            <option key={category.id} value={category.id}>
                                {category.name}
                            </option>
                        ))}
                    </select>
                    <div className="flex items-center gap-2 px-2">
                        <Checkbox
                            id="only_out_of_stock"
                            checked={form.only_out_of_stock}
                            onCheckedChange={(checked) => setForm({ ...form, only_out_of_stock: checked === true })}
                        />
                        <Label htmlFor="only_out_of_stock">Out of stock only</Label>
                    </div>
                    <Button type="submit" variant="secondary">
                        Filter
                    </Button>
                </form>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-4 py-3 font-medium">Product</th>
                                <th className="px-4 py-3 font-medium">Category</th>
                                <th className="px-4 py-3 text-right font-medium">Stock</th>
                                <th className="px-4 py-3 text-right font-medium">Reorder level</th>
                                <th className="px-4 py-3 text-right font-medium">Shortfall</th>
                                <th className="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody>
                            {products.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground px-4 py-8 text-center">
                                        No products are low on stock.
                                    </td>
                                </tr>
                            )}
                            {products.data.map((product) => (
                                <tr key={product.id} className="border-t">
                                    <td className="px-4 py-3">
                                        <Link href={route('products.show', product.id)} className="font-medium hover:underline">
                                            {product.name}
                                        </Link>
                                        <div className="text-muted-foreground font-mono text-xs">{product.sku}</div>
                                    </td>
                                    <td className="px-4 py-3">
                                        {product.category}
                                        {product.subcategory && <span className="text-muted-foreground"> / {product.subcategory}</span>}
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        <StockBadge stock={product.stock} reorderLevel={product.reorder_level} unit={product.unit} />
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">{product.reorder_level}</td>
                                    <td className="px-4 py-3 text-right font-medium tabular-nums">{product.shortfall}</td>
                                    <td className="px-4 py-3 text-right">
                                        {can('inventory.adjust') && (
                                            <Button variant="outline" size="sm" asChild>
                                                <Link href={route('inventory.adjustments.create', { product_id: product.id })}>Adjust</Link>
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={products} />
            </div>
        </AppLayout>
    );
}
