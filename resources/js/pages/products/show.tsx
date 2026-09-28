import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { MovementTable } from '@/features/inventory/movement-table';
import { BarcodeImage } from '@/features/printing/barcode-image';
import { StockBadge } from '@/features/products/stock-badge';
import { type Product, type StockMovement } from '@/features/products/types';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/format';
import { type Paginated } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { useState } from 'react';

interface ShowProductProps {
    product: { data: Product };
    movements: Paginated<StockMovement>;
    barcodeImage: string | null;
}

export default function ShowProduct({ product: { data: product }, movements, barcodeImage }: ShowProductProps) {
    const can = useCan();
    const { errors } = usePage().props as { errors: Record<string, string> };
    const [confirmDelete, setConfirmDelete] = useState(false);

    const details: [string, string][] = [
        ['SKU', product.sku],
        ['Barcode', product.barcode ?? '—'],
        ['Category', [product.category, product.subcategory].filter(Boolean).join(' / ')],
        ['Brand', product.brand ?? '—'],
        ['Unit', product.unit ?? '—'],
        ['Reorder level', String(product.reorder_level)],
    ];

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Products', href: route('products.index') },
                { title: product.name, href: route('products.show', product.id) },
            ]}
        >
            <Head title={product.name} />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="space-y-1">
                        <h2 className="flex items-center gap-2 text-xl font-semibold tracking-tight">
                            {product.name}
                            {!product.is_active && <Badge variant="outline">Inactive</Badge>}
                        </h2>
                        {product.description && <p className="text-muted-foreground text-sm">{product.description}</p>}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {can('inventory.adjust') && (
                            <Button asChild>
                                <Link href={route('inventory.adjustments.create', { product_id: product.id })}>Adjust stock</Link>
                            </Button>
                        )}
                        {can('products.manage') && (
                            <>
                                <Button variant="outline" asChild>
                                    <Link href={route('products.edit', product.id)}>Edit</Link>
                                </Button>
                                <Button variant="destructive" onClick={() => setConfirmDelete(true)}>
                                    Delete
                                </Button>
                            </>
                        )}
                    </div>
                </div>

                <InputError message={errors.record} />

                <div className="grid gap-4 md:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm font-medium">Current stock</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <StockBadge stock={product.stock ?? 0} reorderLevel={product.reorder_level} unit={product.unit} />
                            {product.is_low_stock && <p className="text-muted-foreground mt-2 text-xs">At or below reorder level.</p>}
                        </CardContent>
                    </Card>
                    <Card className="md:col-span-2">
                        <CardHeader>
                            <CardTitle className="text-sm font-medium">Prices</CardTitle>
                        </CardHeader>
                        <CardContent className="grid grid-cols-3 gap-4 text-sm">
                            <div>
                                <div className="text-muted-foreground">Purchase</div>
                                <div className="font-medium tabular-nums">{formatMoney(product.purchase_price)}</div>
                            </div>
                            <div>
                                <div className="text-muted-foreground">Retail</div>
                                <div className="font-medium tabular-nums">{formatMoney(product.retail_price)}</div>
                            </div>
                            <div>
                                <div className="text-muted-foreground">Wholesale</div>
                                <div className="font-medium tabular-nums">{formatMoney(product.wholesale_price)}</div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_300px]">
                    <dl className="grid gap-4 rounded-lg border p-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                        {details.map(([label, value]) => (
                            <div key={label}>
                                <dt className="text-muted-foreground">{label}</dt>
                                <dd className="font-medium">{value}</dd>
                            </div>
                        ))}
                    </dl>

                    <div className="space-y-3 rounded-lg border p-4 text-sm">
                        <h3 className="font-medium">Barcode (Code 128)</h3>
                        {barcodeImage && product.barcode ? (
                            <div className="rounded bg-white p-3">
                                <BarcodeImage src={barcodeImage} value={product.barcode} className="h-14" />
                            </div>
                        ) : (
                            <p className="text-muted-foreground">No barcode yet.</p>
                        )}
                        <div className="flex flex-wrap gap-2">
                            {!product.barcode && can('products.manage') && (
                                <Button
                                    size="sm"
                                    onClick={() => router.post(route('products.barcode.store', product.id), {}, { preserveScroll: true })}
                                >
                                    Generate barcode
                                </Button>
                            )}
                            {product.barcode && can('barcodes.print') && (
                                <Button size="sm" variant="outline" asChild>
                                    <Link href={route('barcodes.labels', { products: [product.id] })}>
                                        <Printer className="size-4" /> Print labels
                                    </Link>
                                </Button>
                            )}
                        </div>
                        <InputError message={errors.barcode} />
                    </div>
                </div>

                <section className="space-y-3">
                    <h3 className="font-medium">Stock history</h3>
                    <MovementTable movements={movements.data} />
                    <Pagination meta={movements.meta} />
                </section>
            </div>

            <ConfirmDialog
                open={confirmDelete}
                onOpenChange={setConfirmDelete}
                title={`Delete ${product.name}?`}
                description="Products with stock history cannot be deleted; deactivate them instead."
                confirmLabel="Delete"
                destructive
                onConfirm={() => router.delete(route('products.destroy', product.id), { onFinish: () => setConfirmDelete(false) })}
            />
        </AppLayout>
    );
}
