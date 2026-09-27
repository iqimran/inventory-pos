import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type Product } from '@/features/products/types';
import { type SelectOption } from '@/features/purchasing/types';
import AppLayout from '@/layouts/app-layout';
import { formatMoney, fromCents, toCents } from '@/lib/format';
import { Head, router, useForm } from '@inertiajs/react';
import { LoaderCircle, ScanBarcode, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface CreatePurchaseProps {
    suppliers: { id: number; name: string; phone: string | null }[];
    methods: SelectOption[];
    supplier: { id: number; balance: string; available_advance: string } | null;
    results: { data: Product[] } | never[];
    q: string;
}

interface Line {
    product_id: number;
    name: string;
    sku: string;
    unit?: string;
    quantity: string;
    unit_cost: string;
}

const selectClass = 'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

export default function CreatePurchase({ suppliers, methods, supplier, results, q }: CreatePurchaseProps) {
    const [search, setSearch] = useState(q);
    const [lines, setLines] = useState<Line[]>([]);
    const matches = 'data' in results ? results.data : [];

    const form = useForm({
        party_id: supplier ? String(supplier.id) : '',
        purchase_date: new Date().toISOString().slice(0, 10),
        supplier_invoice_no: '',
        discount: '0.00',
        paid_amount: '0.00',
        payment_method: 'CASH',
        payment_reference: '',
        apply_advance: true as boolean,
        notes: '',
        items: [] as { product_id: number; quantity: string; unit_cost: string }[],
    });

    const subtotal = lines.reduce((sum, line) => sum + toCents(line.unit_cost) * (Number.parseInt(line.quantity, 10) || 0), 0);
    const total = Math.max(subtotal - toCents(form.data.discount), 0);
    const advance = supplier && form.data.apply_advance ? Math.min(toCents(supplier.available_advance), total) : 0;
    const due = Math.max(total - advance - toCents(form.data.paid_amount), 0);

    const selectSupplier = (partyId: string) => {
        form.setData('party_id', partyId);
        router.reload({ only: ['supplier'], data: { party_id: partyId || undefined } });
    };

    const find: FormEventHandler = (e) => {
        e.preventDefault();
        router.reload({ only: ['results', 'q'], data: { q: search } });
    };

    const addLine = (product: Product) => {
        setLines((current) =>
            current.some((line) => line.product_id === product.id)
                ? current.map((line) =>
                      line.product_id === product.id ? { ...line, quantity: String((Number.parseInt(line.quantity, 10) || 0) + 1) } : line,
                  )
                : [
                      ...current,
                      {
                          product_id: product.id,
                          name: product.name,
                          sku: product.sku,
                          unit: product.unit,
                          quantity: '1',
                          unit_cost: product.purchase_price,
                      },
                  ],
        );
        setSearch('');
    };

    const updateLine = (productId: number, field: 'quantity' | 'unit_cost', value: string) =>
        setLines((current) => current.map((line) => (line.product_id === productId ? { ...line, [field]: value } : line)));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            items: lines.map((line) => ({ product_id: line.product_id, quantity: line.quantity, unit_cost: line.unit_cost })),
        }));
        form.post(route('purchases.store'));
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Purchases', href: route('purchases.index') },
                { title: 'New purchase', href: route('purchases.create') },
            ]}
        >
            <Head title="New purchase" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading title="New purchase" description="Stock is added and the supplier ledger updated when you save." />

                <section className="grid gap-4 md:grid-cols-4">
                    <div className="grid gap-2 md:col-span-2">
                        <Label htmlFor="party_id">Supplier</Label>
                        <select
                            id="party_id"
                            className={selectClass}
                            value={form.data.party_id}
                            onChange={(e) => selectSupplier(e.target.value)}
                            required
                        >
                            <option value="">Select supplier…</option>
                            {suppliers.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.name}
                                    {option.phone ? ` (${option.phone})` : ''}
                                </option>
                            ))}
                        </select>
                        <InputError message={form.errors.party_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="purchase_date">Purchase date</Label>
                        <Input
                            id="purchase_date"
                            type="date"
                            value={form.data.purchase_date}
                            onChange={(e) => form.setData('purchase_date', e.target.value)}
                        />
                        <InputError message={form.errors.purchase_date} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="supplier_invoice_no">Supplier invoice no.</Label>
                        <Input
                            id="supplier_invoice_no"
                            value={form.data.supplier_invoice_no}
                            onChange={(e) => form.setData('supplier_invoice_no', e.target.value)}
                        />
                    </div>
                </section>

                <section className="space-y-3">
                    <form onSubmit={find} className="flex gap-2">
                        <div className="relative flex-1">
                            <ScanBarcode className="text-muted-foreground absolute top-2.5 left-2.5 size-4" />
                            <Input
                                placeholder="Scan barcode or search SKU / name to add"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="pl-8"
                            />
                        </div>
                        <Button type="submit" variant="secondary">
                            Find
                        </Button>
                    </form>
                    {matches.length > 0 && (
                        <ul className="divide-y rounded-lg border">
                            {matches.map((product) => (
                                <li key={product.id}>
                                    <button
                                        type="button"
                                        onClick={() => addLine(product)}
                                        className="hover:bg-muted/50 flex w-full justify-between px-4 py-2 text-left text-sm"
                                    >
                                        <span>
                                            <span className="font-medium">{product.name}</span>
                                            <span className="text-muted-foreground ml-2 font-mono text-xs">{product.sku}</span>
                                        </span>
                                        <span className="text-muted-foreground">
                                            Cost {formatMoney(product.purchase_price)} · Stock {product.stock ?? 0}
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                    {q && matches.length === 0 && <p className="text-muted-foreground text-sm">No active products match “{q}”.</p>}

                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Product</th>
                                    <th className="w-28 px-4 py-3 font-medium">Qty</th>
                                    <th className="w-36 px-4 py-3 font-medium">Unit cost</th>
                                    <th className="px-4 py-3 text-right font-medium">Line total</th>
                                    <th className="w-12 px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody>
                                {lines.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="text-muted-foreground px-4 py-6 text-center">
                                            Add products using the search above.
                                        </td>
                                    </tr>
                                )}
                                {lines.map((line, index) => (
                                    <tr key={line.product_id} className="border-t align-top">
                                        <td className="px-4 py-2">
                                            <div className="font-medium">{line.name}</div>
                                            <div className="text-muted-foreground font-mono text-xs">{line.sku}</div>
                                            <InputError message={form.errors[`items.${index}.product_id` as keyof typeof form.errors]} />
                                        </td>
                                        <td className="px-4 py-2">
                                            <Input
                                                type="number"
                                                min={1}
                                                step={1}
                                                value={line.quantity}
                                                onChange={(e) => updateLine(line.product_id, 'quantity', e.target.value)}
                                                aria-label="Quantity"
                                            />
                                            <InputError message={form.errors[`items.${index}.quantity` as keyof typeof form.errors]} />
                                        </td>
                                        <td className="px-4 py-2">
                                            <Input
                                                type="number"
                                                min={0}
                                                step="0.01"
                                                value={line.unit_cost}
                                                onChange={(e) => updateLine(line.product_id, 'unit_cost', e.target.value)}
                                                aria-label="Unit cost"
                                            />
                                            <InputError message={form.errors[`items.${index}.unit_cost` as keyof typeof form.errors]} />
                                        </td>
                                        <td className="px-4 py-2 text-right tabular-nums">
                                            {formatMoney(fromCents(toCents(line.unit_cost) * (Number.parseInt(line.quantity, 10) || 0)))}
                                        </td>
                                        <td className="px-4 py-2">
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                onClick={() => setLines(lines.filter((l) => l.product_id !== line.product_id))}
                                                aria-label="Remove"
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <InputError message={form.errors.items} />
                </section>

                <form onSubmit={submit} className="grid gap-6 lg:grid-cols-2">
                    <div className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="paid_amount">Paid now</Label>
                                <Input
                                    id="paid_amount"
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    value={form.data.paid_amount}
                                    onChange={(e) => form.setData('paid_amount', e.target.value)}
                                />
                                <InputError message={form.errors.paid_amount} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="payment_method">Payment method</Label>
                                <select
                                    id="payment_method"
                                    className={selectClass}
                                    value={form.data.payment_method}
                                    onChange={(e) => form.setData('payment_method', e.target.value)}
                                >
                                    {methods.map((method) => (
                                        <option key={method.value} value={method.value}>
                                            {method.label}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={form.errors.payment_method} />
                            </div>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="payment_reference">Payment reference</Label>
                            <Input
                                id="payment_reference"
                                value={form.data.payment_reference}
                                onChange={(e) => form.setData('payment_reference', e.target.value)}
                                placeholder="Transaction / cheque no."
                            />
                        </div>
                        {supplier && toCents(supplier.available_advance) > 0 && (
                            <div className="flex items-center gap-2 rounded-lg border p-3">
                                <Checkbox
                                    id="apply_advance"
                                    checked={form.data.apply_advance}
                                    onCheckedChange={(checked) => form.setData('apply_advance', checked === true)}
                                />
                                <Label htmlFor="apply_advance">Use supplier advance (available {formatMoney(supplier.available_advance)})</Label>
                            </div>
                        )}
                        <div className="grid gap-2">
                            <Label htmlFor="notes">Notes</Label>
                            <Input id="notes" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                        </div>
                    </div>

                    <div className="space-y-2 rounded-lg border p-4 text-sm">
                        <div className="flex justify-between">
                            <span>Subtotal</span>
                            <span className="tabular-nums">{formatMoney(fromCents(subtotal))}</span>
                        </div>
                        <div className="flex items-center justify-between gap-4">
                            <Label htmlFor="discount">Discount</Label>
                            <Input
                                id="discount"
                                type="number"
                                min={0}
                                step="0.01"
                                value={form.data.discount}
                                onChange={(e) => form.setData('discount', e.target.value)}
                                className="w-36 text-right"
                            />
                        </div>
                        <InputError message={form.errors.discount} />
                        <div className="flex justify-between border-t pt-2 font-medium">
                            <span>Total</span>
                            <span className="tabular-nums">{formatMoney(fromCents(total))}</span>
                        </div>
                        {advance > 0 && (
                            <div className="flex justify-between">
                                <span>Advance applied</span>
                                <span className="tabular-nums">−{formatMoney(fromCents(advance))}</span>
                            </div>
                        )}
                        <div className="flex justify-between">
                            <span>Paid now</span>
                            <span className="tabular-nums">−{formatMoney(fromCents(toCents(form.data.paid_amount)))}</span>
                        </div>
                        <div className="flex justify-between border-t pt-2 text-base font-semibold">
                            <span>Payable</span>
                            <span className="tabular-nums">{formatMoney(fromCents(due))}</span>
                        </div>
                        <Button type="submit" className="mt-4 w-full" disabled={form.processing || lines.length === 0}>
                            {form.processing && <LoaderCircle className="size-4 animate-spin" />}
                            Save purchase
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
