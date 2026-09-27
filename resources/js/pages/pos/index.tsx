import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type SelectOption } from '@/features/purchasing/types';
import { CustomerPicker } from '@/features/sales/customer-picker';
import { type Customer, type PosProduct } from '@/features/sales/types';
import AppLayout from '@/layouts/app-layout';
import { formatMoney, fromCents, toCents } from '@/lib/format';
import { getJson, HttpError } from '@/lib/http';
import { cn } from '@/lib/utils';
import { Head, router, usePage } from '@inertiajs/react';
import { LoaderCircle, Minus, Plus, ScanBarcode, Trash2 } from 'lucide-react';
import { FormEventHandler, KeyboardEvent, useCallback, useEffect, useMemo, useRef, useState } from 'react';

type Mode = 'RETAIL' | 'WHOLESALE';

interface CartLine {
    product: PosProduct;
    quantity: string;
    unitPrice: string;
    discount: string;
    overridden: boolean;
}

interface PosProps {
    mode: Mode;
    methods: SelectOption[];
    canOverridePrice: boolean;
}

const listPrice = (product: PosProduct, mode: Mode) => (mode === 'RETAIL' ? product.retail_price : product.wholesale_price);
const qty = (line: CartLine) => Math.max(Number.parseInt(line.quantity, 10) || 0, 0);
const lineNetCents = (line: CartLine) => Math.max(toCents(line.unitPrice) * qty(line) - toCents(line.discount), 0);

export default function Pos({ mode: initialMode, methods, canOverridePrice }: PosProps) {
    const { errors } = usePage().props as { errors: Record<string, string> };
    const [mode, setMode] = useState<Mode>(initialMode);
    const [cart, setCart] = useState<CartLine[]>([]);
    const [customer, setCustomer] = useState<Customer | null>(null);
    const [discount, setDiscount] = useState('0');
    const [paid, setPaid] = useState('');
    const [tendered, setTendered] = useState('');
    const [method, setMethod] = useState('CASH');
    const [notes, setNotes] = useState('');
    const [scan, setScan] = useState('');
    const [results, setResults] = useState<PosProduct[]>([]);
    const [message, setMessage] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const scanRef = useRef<HTMLInputElement>(null);
    const paidRef = useRef<HTMLInputElement>(null);
    const searchAbort = useRef<AbortController | null>(null);

    const subtotalCents = useMemo(() => cart.reduce((sum, line) => sum + lineNetCents(line), 0), [cart]);
    const totalCents = Math.max(subtotalCents - toCents(discount), 0);
    const paidCents = paid === '' ? 0 : toCents(paid);
    const dueCents = Math.max(totalCents - paidCents, 0);
    const changeCents = tendered === '' ? 0 : Math.max(toCents(tendered) - paidCents, 0);
    const itemCount = cart.reduce((sum, line) => sum + qty(line), 0);

    const addProduct = useCallback(
        (product: PosProduct) => {
            setCart((current) => {
                const existing = current.find((line) => line.product.id === product.id);

                if (existing) {
                    return current.map((line) => (line.product.id === product.id ? { ...line, quantity: String(qty(line) + 1) } : line));
                }

                return [...current, { product, quantity: '1', unitPrice: listPrice(product, mode), discount: '0', overridden: false }];
            });
            setMessage(product.stock <= 0 ? `${product.name} shows no stock on hand.` : null);
            setScan('');
            setResults([]);
            scanRef.current?.focus();
        },
        [mode],
    );

    // Live search while typing (not for scanner input, which ends with Enter).
    useEffect(() => {
        const term = scan.trim();

        if (term.length < 2) {
            setResults([]);
            return;
        }

        const timer = window.setTimeout(() => {
            searchAbort.current?.abort();
            searchAbort.current = new AbortController();
            getJson<{ data: PosProduct[] }>(route('pos.products', { q: term }), searchAbort.current.signal)
                .then((response) => setResults(response.data))
                .catch(() => undefined);
        }, 250);

        return () => window.clearTimeout(timer);
    }, [scan]);

    const submitScan: FormEventHandler = async (e) => {
        e.preventDefault();
        const code = scan.trim();

        if (code === '') return;

        // Exact barcode/SKU first (scanner), otherwise fall back to the first search match.
        try {
            const response = await getJson<{ data: PosProduct }>(route('pos.products.lookup', { code }));
            addProduct(response.data);
        } catch (error) {
            if (error instanceof HttpError && error.status === 404 && results.length === 1) {
                addProduct(results[0]);
            } else {
                setMessage(error instanceof HttpError ? (error.body.message ?? 'Lookup failed.') : 'Lookup failed.');
            }
        }
    };

    const updateLine = (productId: number, changes: Partial<CartLine>) =>
        setCart((current) => current.map((line) => (line.product.id === productId ? { ...line, ...changes } : line)));

    const switchMode = (next: Mode) => {
        setMode(next);
        // Re-price lines that were not manually overridden.
        setCart((current) => current.map((line) => (line.overridden ? line : { ...line, unitPrice: listPrice(line.product, next) })));
    };

    const reset = () => {
        setCart([]);
        setCustomer(null);
        setDiscount('0');
        setPaid('');
        setTendered('');
        setNotes('');
        setMessage(null);
        scanRef.current?.focus();
    };

    const complete = () => {
        if (cart.length === 0 || submitting) return;

        setSubmitting(true);
        router.post(
            route('sales.store'),
            {
                sale_type: mode,
                party_id: customer?.id ?? '',
                items: cart.map((line) => ({
                    product_id: line.product.id,
                    quantity: line.quantity,
                    unit_price: line.overridden ? line.unitPrice : null,
                    discount: line.discount || '0',
                })),
                discount: discount || '0',
                paid_amount: paid === '' ? '0' : paid,
                payment_method: method,
                tendered_amount: tendered,
                notes,
            },
            { onFinish: () => setSubmitting(false) },
        );
    };

    const onKeyDown = (e: KeyboardEvent<HTMLDivElement>) => {
        const shortcuts: Record<string, () => void> = {
            F2: () => scanRef.current?.focus(),
            F4: () => paidRef.current?.focus(),
            F8: () => setPaid(fromCents(totalCents)),
            F9: complete,
            Escape: () => setResults([]),
        };

        if (shortcuts[e.key]) {
            e.preventDefault();
            shortcuts[e.key]();
        }
    };

    useEffect(() => scanRef.current?.focus(), []);

    const lineErrors = (index: number) =>
        ['product_id', 'quantity', 'unit_price', 'discount'].map((field) => errors[`items.${index}.${field}`]).filter(Boolean);

    return (
        <AppLayout breadcrumbs={[{ title: 'Point of sale', href: route('pos.index') }]}>
            <Head title="POS" />
            <div className="grid flex-1 gap-4 p-3 md:p-4 lg:grid-cols-[minmax(0,1fr)_380px]" onKeyDown={onKeyDown}>
                <section className="flex min-w-0 flex-col gap-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <div className="inline-flex rounded-lg border p-1" role="group" aria-label="Sale mode">
                            {(['RETAIL', 'WHOLESALE'] as Mode[]).map((option) => (
                                <button
                                    key={option}
                                    type="button"
                                    onClick={() => switchMode(option)}
                                    className={cn(
                                        'rounded-md px-3 py-1.5 text-sm',
                                        mode === option ? 'bg-primary text-primary-foreground' : 'hover:bg-muted',
                                    )}
                                    aria-pressed={mode === option}
                                >
                                    {option === 'RETAIL' ? 'Retail' : 'Wholesale'}
                                </button>
                            ))}
                        </div>
                        <p className="text-muted-foreground hidden text-xs md:block">F2 scan · F4 paid · F8 exact amount · F9 complete · Esc close</p>
                    </div>

                    <form onSubmit={submitScan} className="relative">
                        <ScanBarcode className="text-muted-foreground absolute top-3 left-3 size-5" />
                        <Input
                            ref={scanRef}
                            value={scan}
                            onChange={(e) => setScan(e.target.value)}
                            placeholder="Scan barcode, or type SKU / product name"
                            className="h-11 pl-10 text-base"
                            autoComplete="off"
                            aria-label="Scan or search product"
                        />
                        {results.length > 0 && (
                            <ul className="bg-background absolute z-20 mt-1 max-h-80 w-full divide-y overflow-auto rounded-md border shadow-lg">
                                {results.map((product) => (
                                    <li key={product.id}>
                                        <button
                                            type="button"
                                            onClick={() => addProduct(product)}
                                            className="hover:bg-muted flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm"
                                        >
                                            <span className="min-w-0">
                                                <span className="block truncate font-medium">{product.name}</span>
                                                <span className="text-muted-foreground font-mono text-xs">{product.sku}</span>
                                            </span>
                                            <span className="shrink-0 text-right">
                                                <span className="block tabular-nums">{formatMoney(listPrice(product, mode))}</span>
                                                <span className={cn('text-xs', product.stock <= 0 ? 'text-destructive' : 'text-muted-foreground')}>
                                                    Stock {product.stock}
                                                </span>
                                            </span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </form>

                    {message && (
                        <p className="rounded-md border border-amber-500/40 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950 dark:text-amber-200">
                            {message}
                        </p>
                    )}

                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-3 py-2 font-medium">Item</th>
                                    <th className="w-36 px-3 py-2 font-medium">Qty</th>
                                    <th className="w-32 px-3 py-2 font-medium">Price</th>
                                    <th className="w-28 px-3 py-2 font-medium">Discount</th>
                                    <th className="px-3 py-2 text-right font-medium">Total</th>
                                    <th className="w-10 px-3 py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {cart.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="text-muted-foreground px-3 py-10 text-center">
                                            Scan a barcode or search to add items.
                                        </td>
                                    </tr>
                                )}
                                {cart.map((line, index) => (
                                    <tr key={line.product.id} className="border-t align-top">
                                        <td className="px-3 py-2">
                                            <div className="font-medium">{line.product.name}</div>
                                            <div className="text-muted-foreground text-xs">
                                                <span className="font-mono">{line.product.sku}</span> · stock {line.product.stock}
                                                {qty(line) > line.product.stock && <span className="text-destructive"> · exceeds stock</span>}
                                            </div>
                                            {lineErrors(index).map((error) => (
                                                <InputError key={error} message={error} />
                                            ))}
                                        </td>
                                        <td className="px-3 py-2">
                                            <div className="flex items-center gap-1">
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="icon"
                                                    className="size-8"
                                                    onClick={() => updateLine(line.product.id, { quantity: String(Math.max(qty(line) - 1, 1)) })}
                                                    aria-label="Decrease"
                                                >
                                                    <Minus className="size-3" />
                                                </Button>
                                                <Input
                                                    type="number"
                                                    min={1}
                                                    value={line.quantity}
                                                    onChange={(e) => updateLine(line.product.id, { quantity: e.target.value })}
                                                    className="h-8 w-14 px-1 text-center"
                                                    aria-label={`Quantity of ${line.product.name}`}
                                                />
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="icon"
                                                    className="size-8"
                                                    onClick={() => updateLine(line.product.id, { quantity: String(qty(line) + 1) })}
                                                    aria-label="Increase"
                                                >
                                                    <Plus className="size-3" />
                                                </Button>
                                            </div>
                                        </td>
                                        <td className="px-3 py-2">
                                            {canOverridePrice ? (
                                                <Input
                                                    type="number"
                                                    min={0}
                                                    step="0.01"
                                                    value={line.unitPrice}
                                                    onChange={(e) =>
                                                        updateLine(line.product.id, {
                                                            unitPrice: e.target.value,
                                                            overridden: toCents(e.target.value) !== toCents(listPrice(line.product, mode)),
                                                        })
                                                    }
                                                    className={cn('h-8', line.overridden && 'border-amber-500')}
                                                    aria-label={`Price of ${line.product.name}`}
                                                />
                                            ) : (
                                                <span className="tabular-nums">{formatMoney(line.unitPrice)}</span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            <Input
                                                type="number"
                                                min={0}
                                                step="0.01"
                                                value={line.discount}
                                                onChange={(e) => updateLine(line.product.id, { discount: e.target.value })}
                                                className="h-8"
                                                aria-label={`Discount on ${line.product.name}`}
                                            />
                                        </td>
                                        <td className="px-3 py-2 text-right font-medium tabular-nums">
                                            {formatMoney(fromCents(lineNetCents(line)))}
                                        </td>
                                        <td className="px-3 py-2">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="size-8"
                                                onClick={() => setCart(cart.filter((l) => l.product.id !== line.product.id))}
                                                aria-label={`Remove ${line.product.name}`}
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

                <aside className="flex flex-col gap-4 rounded-lg border p-4 lg:sticky lg:top-4 lg:self-start">
                    <div className="grid gap-2">
                        <Label>Customer</Label>
                        <CustomerPicker value={customer} onChange={setCustomer} />
                        <InputError message={errors.party_id} />
                    </div>

                    <div className="space-y-2 text-sm">
                        <div className="flex justify-between">
                            <span>
                                Items <Badge variant="secondary">{itemCount}</Badge>
                            </span>
                            <span className="tabular-nums">{formatMoney(fromCents(subtotalCents))}</span>
                        </div>
                        <div className="flex items-center justify-between gap-2">
                            <Label htmlFor="discount">Invoice discount</Label>
                            <Input
                                id="discount"
                                type="number"
                                min={0}
                                step="0.01"
                                value={discount}
                                onChange={(e) => setDiscount(e.target.value)}
                                className="h-8 w-32 text-right"
                            />
                        </div>
                        <InputError message={errors.discount} />
                        <div className="flex justify-between border-t pt-2 text-xl font-semibold">
                            <span>Total</span>
                            <span className="tabular-nums">{formatMoney(fromCents(totalCents))}</span>
                        </div>
                    </div>

                    <div className="grid gap-3">
                        <div className="grid grid-cols-2 gap-2">
                            <div className="grid gap-1">
                                <Label htmlFor="method">Method</Label>
                                <select
                                    id="method"
                                    value={method}
                                    onChange={(e) => setMethod(e.target.value)}
                                    className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                                >
                                    {methods.map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="paid" className="flex justify-between">
                                    Paid
                                    <button
                                        type="button"
                                        className="text-muted-foreground text-xs hover:underline"
                                        onClick={() => setPaid(fromCents(totalCents))}
                                    >
                                        Exact (F8)
                                    </button>
                                </Label>
                                <Input
                                    id="paid"
                                    ref={paidRef}
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    value={paid}
                                    onChange={(e) => setPaid(e.target.value)}
                                    placeholder="0.00"
                                />
                            </div>
                        </div>
                        <InputError message={errors.paid_amount} />
                        {method === 'CASH' && (
                            <div className="grid gap-1">
                                <Label htmlFor="tendered">Cash tendered (optional)</Label>
                                <Input
                                    id="tendered"
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    value={tendered}
                                    onChange={(e) => setTendered(e.target.value)}
                                />
                                <InputError message={errors.tendered_amount} />
                            </div>
                        )}
                    </div>

                    <div className="space-y-1 rounded-md bg-neutral-50 p-3 text-sm dark:bg-neutral-900">
                        <div className="flex justify-between">
                            <span>Due</span>
                            <span className={cn('font-semibold tabular-nums', dueCents > 0 && 'text-destructive')}>
                                {formatMoney(fromCents(dueCents))}
                            </span>
                        </div>
                        {changeCents > 0 && (
                            <div className="flex justify-between">
                                <span>Change</span>
                                <span className="font-semibold tabular-nums">{formatMoney(fromCents(changeCents))}</span>
                            </div>
                        )}
                        {dueCents > 0 && !customer && <p className="text-destructive text-xs">Select a customer to sell on due.</p>}
                    </div>

                    <Input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Note (optional)" aria-label="Note" />

                    <InputError message={errors.quantity} />
                    <InputError message={errors.items} />

                    <div className="grid grid-cols-[auto_1fr] gap-2">
                        <Button type="button" variant="outline" onClick={reset} disabled={submitting}>
                            Clear
                        </Button>
                        <Button type="button" size="lg" onClick={complete} disabled={submitting || cart.length === 0 || (dueCents > 0 && !customer)}>
                            {submitting && <LoaderCircle className="size-4 animate-spin" />}
                            Complete sale (F9)
                        </Button>
                    </div>
                </aside>
            </div>
        </AppLayout>
    );
}
