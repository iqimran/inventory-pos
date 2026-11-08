import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StockBadge } from '@/features/products/stock-badge';
import { type Product } from '@/features/products/types';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, router, useForm } from '@inertiajs/react';
import { LoaderCircle, ScanBarcode } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Reason {
    value: string;
    label: string;
    directions: ('in' | 'out')[];
}

interface CreateAdjustmentProps {
    reasons: Reason[];
    selected: { data: Product } | null;
    results: { data: Product[] } | never[];
    q: string;
}

const selectClass = 'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

export default function CreateAdjustment({ reasons, selected, results, q }: CreateAdjustmentProps) {
    const [search, setSearch] = useState(q);
    const [product, setProduct] = useState<Product | null>(selected?.data ?? null);
    const matches = 'data' in results ? results.data : [];

    const { data, setData, post, processing, errors } = useForm({
        product_id: selected?.data.id ? String(selected.data.id) : '',
        direction: 'in' as 'in' | 'out',
        quantity: '1',
        reason: '',
        notes: '',
    });

    const availableReasons = reasons.filter((reason) => reason.directions.includes(data.direction));
    const currentStock = product?.stock ?? 0;
    const quantity = Number.parseInt(data.quantity, 10) || 0;
    const projected = currentStock + (data.direction === 'in' ? quantity : -quantity);

    const find: FormEventHandler = (e) => {
        e.preventDefault();
        router.reload({ only: ['results', 'q'], data: { q: search } });
    };

    const choose = (match: Product) => {
        setProduct(match);
        setData('product_id', String(match.id));
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('inventory.adjustments.store'));
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Stock adjustments', href: route('inventory.adjustments.index') },
                { title: 'New adjustment', href: route('inventory.adjustments.create') },
            ]}
        >
            <Head title="New stock adjustment" />
            <div className="max-w-3xl space-y-8 p-4 md:p-6">
                <Heading title="New stock adjustment" description="Every adjustment is recorded as a permanent stock movement with its reason." />

                <section className="space-y-3">
                    <Label htmlFor="product-search" required>
                        Product
                    </Label>
                    <form onSubmit={find} className="flex gap-2">
                        <div className="relative flex-1">
                            <ScanBarcode className="text-muted-foreground absolute top-2.5 left-2.5 size-4" />
                            <Input
                                id="product-search"
                                placeholder="Scan barcode or search SKU / name"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="pl-8"
                                autoFocus={!product}
                            />
                        </div>
                        <Button type="submit" variant="secondary">
                            Find
                        </Button>
                    </form>

                    {matches.length > 0 && (
                        <ul className="divide-y rounded-lg border">
                            {matches.map((match) => (
                                <li key={match.id}>
                                    <button
                                        type="button"
                                        onClick={() => choose(match)}
                                        className={cn(
                                            'hover:bg-muted/50 flex w-full items-center justify-between gap-4 px-4 py-2 text-left text-sm',
                                            product?.id === match.id && 'bg-muted',
                                        )}
                                    >
                                        <span>
                                            <span className="font-medium">{match.name}</span>
                                            <span className="text-muted-foreground ml-2 font-mono text-xs">{match.sku}</span>
                                        </span>
                                        <StockBadge stock={match.stock ?? 0} reorderLevel={match.reorder_level} unit={match.unit} />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                    {q && matches.length === 0 && <p className="text-muted-foreground text-sm">No products match “{q}”.</p>}
                    <InputError message={errors.product_id} />
                </section>

                {product && (
                    <form onSubmit={submit} className="space-y-6 rounded-lg border p-4">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <div className="font-medium">{product.name}</div>
                                <div className="text-muted-foreground font-mono text-xs">{product.sku}</div>
                            </div>
                            <div className="text-sm">
                                Current stock: <StockBadge stock={currentStock} reorderLevel={product.reorder_level} unit={product.unit} />
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="direction" required>
                                    Direction
                                </Label>
                                <select
                                    id="direction"
                                    className={selectClass}
                                    value={data.direction}
                                    onChange={(e) => setData((current) => ({ ...current, direction: e.target.value as 'in' | 'out', reason: '' }))}
                                >
                                    <option value="in">Add stock (+)</option>
                                    <option value="out">Remove stock (−)</option>
                                </select>
                                <InputError message={errors.direction} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="quantity" required>
                                    Quantity
                                </Label>
                                <Input
                                    id="quantity"
                                    type="number"
                                    min={1}
                                    step={1}
                                    value={data.quantity}
                                    onChange={(e) => setData('quantity', e.target.value)}
                                    required
                                />
                                <InputError message={errors.quantity} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="reason" required>
                                    Reason
                                </Label>
                                <select
                                    id="reason"
                                    className={selectClass}
                                    value={data.reason}
                                    onChange={(e) => setData('reason', e.target.value)}
                                    required
                                >
                                    <option value="">Select reason…</option>
                                    {availableReasons.map((reason) => (
                                        <option key={reason.value} value={reason.value}>
                                            {reason.label}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.reason} />
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="notes" required={data.reason === 'OTHER'}>
                                Notes {data.reason === 'OTHER' ? '' : '(optional)'}
                            </Label>
                            <textarea
                                id="notes"
                                rows={3}
                                value={data.notes}
                                onChange={(e) => setData('notes', e.target.value)}
                                className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                            />
                            <InputError message={errors.notes} />
                        </div>

                        <p className={cn('text-sm', projected < 0 ? 'text-destructive' : 'text-muted-foreground')}>
                            Balance after adjustment: <span className="font-medium tabular-nums">{projected}</span>
                        </p>

                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="size-4 animate-spin" />}
                            Record adjustment
                        </Button>
                    </form>
                )}
            </div>
        </AppLayout>
    );
}
