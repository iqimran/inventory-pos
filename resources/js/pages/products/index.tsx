import Heading from '@/components/heading';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { StockBadge } from '@/features/products/stock-badge';
import { type Product } from '@/features/products/types';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/format';
import { type Option, type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Plus, ScanBarcode } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Filters {
    q: string;
    category_id: number | null;
    subcategory_id: number | null;
    brand_id: number | null;
    status: string | null;
}

interface ProductsIndexProps {
    products: Paginated<Product>;
    filters: Filters;
    options: { categories: Option[]; subcategories: (Option & { category_id: number })[]; brands: Option[] };
}

const selectClass = 'border-input bg-background h-9 rounded-md border px-3 text-sm';

export default function ProductsIndex({ products, filters, options }: ProductsIndexProps) {
    const can = useCan();
    const [form, setForm] = useState({
        q: filters.q,
        category_id: filters.category_id ? String(filters.category_id) : '',
        subcategory_id: filters.subcategory_id ? String(filters.subcategory_id) : '',
        brand_id: filters.brand_id ? String(filters.brand_id) : '',
        status: filters.status ?? '',
    });

    const subcategories = options.subcategories.filter((subcategory) => String(subcategory.category_id) === form.category_id);

    const apply: FormEventHandler = (e) => {
        e.preventDefault();
        const query = Object.fromEntries(Object.entries(form).filter(([, value]) => value !== ''));
        router.get(route('products.index'), query, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Products', href: route('products.index') }]}>
            <Head title="Products" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <Heading title="Products" description="Search by barcode, SKU or name. Exact barcode and SKU matches are listed first." />
                    {can('products.manage') && (
                        <Button asChild>
                            <Link href={route('products.create')}>
                                <Plus className="size-4" /> New product
                            </Link>
                        </Button>
                    )}
                </div>

                <form onSubmit={apply} className="grid gap-2 md:grid-cols-[minmax(0,2fr)_repeat(4,minmax(0,1fr))_auto]">
                    <div className="relative">
                        <ScanBarcode className="text-muted-foreground absolute top-2.5 left-2.5 size-4" />
                        <Input
                            placeholder="Barcode, SKU or name"
                            value={form.q}
                            onChange={(e) => setForm({ ...form, q: e.target.value })}
                            className="pl-8"
                            autoFocus
                        />
                    </div>
                    <select
                        className={selectClass}
                        value={form.category_id}
                        onChange={(e) => setForm({ ...form, category_id: e.target.value, subcategory_id: '' })}
                        aria-label="Category"
                    >
                        <option value="">All categories</option>
                        {options.categories.map((category) => (
                            <option key={category.id} value={category.id}>
                                {category.name}
                            </option>
                        ))}
                    </select>
                    <select
                        className={selectClass}
                        value={form.subcategory_id}
                        onChange={(e) => setForm({ ...form, subcategory_id: e.target.value })}
                        disabled={!form.category_id}
                        aria-label="Subcategory"
                    >
                        <option value="">All subcategories</option>
                        {subcategories.map((subcategory) => (
                            <option key={subcategory.id} value={subcategory.id}>
                                {subcategory.name}
                            </option>
                        ))}
                    </select>
                    <select
                        className={selectClass}
                        value={form.brand_id}
                        onChange={(e) => setForm({ ...form, brand_id: e.target.value })}
                        aria-label="Brand"
                    >
                        <option value="">All brands</option>
                        {options.brands.map((brand) => (
                            <option key={brand.id} value={brand.id}>
                                {brand.name}
                            </option>
                        ))}
                    </select>
                    <select
                        className={selectClass}
                        value={form.status}
                        onChange={(e) => setForm({ ...form, status: e.target.value })}
                        aria-label="Status"
                    >
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                    <Button type="submit" variant="secondary">
                        Search
                    </Button>
                </form>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-4 py-3 font-medium">Product</th>
                                <th className="px-4 py-3 font-medium">SKU / Barcode</th>
                                <th className="px-4 py-3 font-medium">Category</th>
                                <th className="px-4 py-3 text-right font-medium">Retail</th>
                                <th className="px-4 py-3 text-right font-medium">Wholesale</th>
                                <th className="px-4 py-3 text-right font-medium">Stock</th>
                            </tr>
                        </thead>
                        <tbody>
                            {products.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground px-4 py-8 text-center">
                                        No products found.
                                    </td>
                                </tr>
                            )}
                            {products.data.map((product) => (
                                <tr key={product.id} className="hover:bg-muted/30 border-t">
                                    <td className="px-4 py-3">
                                        <Link href={route('products.show', product.id)} className="font-medium hover:underline">
                                            {product.name}
                                        </Link>
                                        <div className="text-muted-foreground flex items-center gap-2 text-xs">
                                            {product.brand && <span>{product.brand}</span>}
                                            {!product.is_active && <Badge variant="outline">Inactive</Badge>}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3 font-mono text-xs">
                                        <div>{product.sku}</div>
                                        <div className="text-muted-foreground">{product.barcode ?? '—'}</div>
                                    </td>
                                    <td className="px-4 py-3">
                                        {product.category}
                                        {product.subcategory && <span className="text-muted-foreground"> / {product.subcategory}</span>}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">{formatMoney(product.retail_price)}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">{formatMoney(product.wholesale_price)}</td>
                                    <td className="px-4 py-3 text-right">
                                        <StockBadge stock={product.stock ?? 0} reorderLevel={product.reorder_level} unit={product.unit} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={products.meta} />
            </div>
        </AppLayout>
    );
}
