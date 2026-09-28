import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type SelectOption } from '@/features/purchasing/types';
import { type PosProduct } from '@/features/sales/types';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/format';
import { getJson, HttpError, postJson } from '@/lib/http';
import { Head, router, usePage } from '@inertiajs/react';
import { Printer, ScanBarcode, Trash2 } from 'lucide-react';
import { FormEventHandler, useEffect, useRef, useState } from 'react';

interface LabelProduct {
    id: number;
    name: string;
    sku: string;
    barcode: string | null;
    retail_price: string;
    wholesale_price: string;
}

interface Row {
    product: LabelProduct;
    quantity: string;
}

interface LabelsProps {
    layouts: SelectOption[];
    preselected: LabelProduct[];
}

const selectClass = 'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

/**
 * Choose products and label quantities, then open the printable label sheet.
 */
export default function BarcodeLabels({ layouts, preselected }: LabelsProps) {
    const can = useCan();
    const { errors } = usePage().props as { errors: Record<string, string> };
    const [rows, setRows] = useState<Row[]>(preselected.map((product) => ({ product, quantity: '1' })));
    const [layout, setLayout] = useState(layouts[0]?.value ?? '');
    const [price, setPrice] = useState('retail');
    const [showSku, setShowSku] = useState(true);
    const [showShop, setShowShop] = useState(false);
    const [term, setTerm] = useState('');
    const [results, setResults] = useState<PosProduct[]>([]);
    const [message, setMessage] = useState<string | null>(null);
    const abort = useRef<AbortController | null>(null);

    useEffect(() => {
        if (term.trim().length < 2) {
            setResults([]);
            return;
        }

        const timer = window.setTimeout(() => {
            abort.current?.abort();
            abort.current = new AbortController();
            getJson<{ data: PosProduct[] }>(route('pos.products', { q: term }), abort.current.signal)
                .then((response) => setResults(response.data))
                .catch(() => undefined);
        }, 250);

        return () => window.clearTimeout(timer);
    }, [term]);

    const add = (product: PosProduct) => {
        setRows((current) =>
            current.some((row) => row.product.id === product.id)
                ? current.map((row) => (row.product.id === product.id ? { ...row, quantity: String((Number(row.quantity) || 0) + 1) } : row))
                : [...current, { product, quantity: '1' }],
        );
        setTerm('');
        setResults([]);
    };

    // Scanner / Enter: add the exact barcode or SKU match.
    const submitSearch: FormEventHandler = async (e) => {
        e.preventDefault();
        const code = term.trim();
        if (!code) return;

        try {
            const response = await getJson<{ data: PosProduct }>(route('pos.products.lookup', { code }));
            add(response.data);
            setMessage(null);
        } catch (error) {
            if (results.length === 1) add(results[0]);
            else setMessage(error instanceof HttpError ? (error.body.message ?? 'Not found.') : 'Lookup failed.');
        }
    };

    const generate = async (product: LabelProduct) => {
        try {
            const response = await postJson<{ data: { id: number; barcode: string } }>(route('products.barcode.store', product.id), {});
            setRows((current) =>
                current.map((row) => (row.product.id === product.id ? { ...row, product: { ...row.product, barcode: response.data.barcode } } : row)),
            );
        } catch (error) {
            setMessage(error instanceof HttpError ? (error.body.message ?? 'Could not generate a barcode.') : 'Could not generate a barcode.');
        }
    };

    const total = rows.reduce((sum, row) => sum + (Number.parseInt(row.quantity, 10) || 0), 0);
    const missing = rows.filter((row) => !row.product.barcode);

    const preview = () => {
        // Explicit indexes: GET serialises arrays as `items[][…]`, which PHP would split into separate items.
        const items = Object.fromEntries(
            rows.flatMap((row, index) => [
                [`items[${index}][product_id]`, row.product.id],
                [`items[${index}][quantity]`, row.quantity],
            ]),
        );

        router.get(
            route('barcodes.labels.print'),
            {
                ...items,
                layout,
                price,
                show_sku: showSku ? 1 : 0,
                show_shop: showShop ? 1 : 0,
            },
            { preserveState: true },
        );
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Barcode labels', href: route('barcodes.labels') }]}>
            <Head title="Barcode labels" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading title="Barcode labels" description="Code 128 labels with product name, barcode, barcode number, SKU and price." />

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
                    <section className="min-w-0 space-y-3">
                        <form onSubmit={submitSearch} className="relative">
                            <ScanBarcode className="text-muted-foreground absolute top-2.5 left-2.5 size-4" />
                            <Input
                                value={term}
                                onChange={(e) => setTerm(e.target.value)}
                                placeholder="Scan or search product name, SKU or barcode"
                                className="pl-8"
                                aria-label="Add product"
                                autoFocus
                            />
                            {results.length > 0 && (
                                <ul className="bg-background absolute z-20 mt-1 max-h-72 w-full divide-y overflow-auto rounded-md border shadow-lg">
                                    {results.map((product) => (
                                        <li key={product.id}>
                                            <button
                                                type="button"
                                                onClick={() => add(product)}
                                                className="hover:bg-muted flex w-full justify-between gap-3 px-3 py-2 text-left text-sm"
                                            >
                                                <span>
                                                    <span className="font-medium">{product.name}</span>
                                                    <span className="text-muted-foreground ml-2 font-mono text-xs">{product.sku}</span>
                                                </span>
                                                <span className="text-muted-foreground font-mono text-xs">{product.barcode ?? 'no barcode'}</span>
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </form>
                        {message && <p className="text-destructive text-sm">{message}</p>}

                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">Product</th>
                                        <th className="px-3 py-2 font-medium">Barcode</th>
                                        <th className="px-3 py-2 text-right font-medium">Price</th>
                                        <th className="w-28 px-3 py-2 font-medium">Labels</th>
                                        <th className="w-10 px-3 py-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.length === 0 && (
                                        <tr>
                                            <td colSpan={5} className="text-muted-foreground px-3 py-8 text-center">
                                                Scan or search products to add labels.
                                            </td>
                                        </tr>
                                    )}
                                    {rows.map((row) => (
                                        <tr key={row.product.id} className="border-t">
                                            <td className="px-3 py-2">
                                                <div className="font-medium">{row.product.name}</div>
                                                <div className="text-muted-foreground font-mono text-xs">{row.product.sku}</div>
                                            </td>
                                            <td className="px-3 py-2 font-mono text-xs">
                                                {row.product.barcode ??
                                                    (can('products.manage') ? (
                                                        <Button type="button" size="sm" variant="secondary" onClick={() => generate(row.product)}>
                                                            Generate
                                                        </Button>
                                                    ) : (
                                                        <span className="text-destructive">No barcode</span>
                                                    ))}
                                            </td>
                                            <td className="px-3 py-2 text-right tabular-nums">
                                                {price === 'none'
                                                    ? '—'
                                                    : formatMoney(price === 'wholesale' ? row.product.wholesale_price : row.product.retail_price)}
                                            </td>
                                            <td className="px-3 py-2">
                                                <Input
                                                    type="number"
                                                    min={1}
                                                    max={2000}
                                                    value={row.quantity}
                                                    onChange={(e) =>
                                                        setRows((current) =>
                                                            current.map((r) =>
                                                                r.product.id === row.product.id ? { ...r, quantity: e.target.value } : r,
                                                            ),
                                                        )
                                                    }
                                                    className="h-8"
                                                    aria-label={`Labels for ${row.product.name}`}
                                                />
                                            </td>
                                            <td className="px-3 py-2">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8"
                                                    onClick={() => setRows((current) => current.filter((r) => r.product.id !== row.product.id))}
                                                    aria-label={`Remove ${row.product.name}`}
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <aside className="space-y-4 rounded-lg border p-4 lg:self-start">
                        <div className="grid gap-2">
                            <Label htmlFor="layout">Label stock</Label>
                            <select id="layout" className={selectClass} value={layout} onChange={(e) => setLayout(e.target.value)}>
                                {layouts.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.layout} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="price">Price on label</Label>
                            <select id="price" className={selectClass} value={price} onChange={(e) => setPrice(e.target.value)}>
                                <option value="retail">Retail price</option>
                                <option value="wholesale">Wholesale price</option>
                                <option value="none">No price</option>
                            </select>
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={showSku} onChange={(e) => setShowSku(e.target.checked)} />
                            Show SKU
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={showShop} onChange={(e) => setShowShop(e.target.checked)} />
                            Show shop name
                        </label>

                        <div className="border-t pt-3 text-sm">
                            <div className="flex justify-between font-medium">
                                <span>Total labels</span>
                                <span className="tabular-nums">{total}</span>
                            </div>
                            {missing.length > 0 && (
                                <p className="text-destructive mt-1 text-xs">{missing.length} product(s) need a barcode before printing.</p>
                            )}
                        </div>
                        {Object.entries(errors)
                            .filter(([key]) => key.startsWith('items'))
                            .map(([key, error]) => (
                                <InputError key={key} message={error} />
                            ))}

                        <Button className="w-full" onClick={preview} disabled={rows.length === 0 || total === 0 || missing.length > 0}>
                            <Printer className="size-4" /> Preview & print
                        </Button>
                    </aside>
                </div>
            </div>
        </AppLayout>
    );
}
