import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PartyBalance } from '@/features/parties/balance';
import { type SelectOption } from '@/features/purchasing/types';
import { type Sale } from '@/features/sales/types';
import AppLayout from '@/layouts/app-layout';
import { formatMoney, fromCents, toCents } from '@/lib/format';
import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

/**
 * Estimated value of returning `qty` units of a line (the server computes the exact figure).
 */
function estimate(lineTotal: string, sold: number, qty: number): number {
    return sold > 0 ? Math.round((toCents(lineTotal) * qty) / sold) : 0;
}

interface CreateSaleReturnProps {
    sale: { data: Sale };
    methods: SelectOption[];
    customerBalance: string | null;
}

export default function CreateSaleReturn({ sale: { data: sale }, methods, customerBalance }: CreateSaleReturnProps) {
    const items = sale.items ?? [];
    const walkIn = !sale.party;
    const form = useForm({
        reason: '',
        refund_amount: '',
        refund_method: 'CASH',
        items: items.map((item) => ({ sale_item_id: item.id, quantity: '0' })),
    });
    const errors = form.errors as Record<string, string>;

    const valueCents = items.reduce(
        (sum, item, index) => sum + estimate(item.line_total, item.quantity, Number.parseInt(form.data.items[index].quantity, 10) || 0),
        0,
    );
    const dueCents = toCents(sale.due_amount);
    const adjustmentCents = Math.min(valueCents, dueCents);
    const paidPartCents = valueCents - adjustmentCents;

    const setQuantity = (index: number, quantity: string) =>
        form.setData(
            'items',
            form.data.items.map((line, i) => (i === index ? { ...line, quantity } : line)),
        );

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.transform((data) => ({ ...data, refund_amount: walkIn ? '' : data.refund_amount }));
        form.post(route('sales.returns.store', sale.id));
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Sales', href: route('sales.index') },
                { title: sale.invoice_no, href: route('sales.show', sale.id) },
                { title: 'Return', href: route('sales.returns.create', sale.id) },
            ]}
        >
            <Head title={`Return — ${sale.invoice_no}`} />
            <form onSubmit={submit} className="max-w-4xl space-y-6 p-4 md:p-6">
                <Heading
                    title={`Return items from ${sale.invoice_no}`}
                    description="Returned goods go back into stock. Items are valued at the price the customer actually paid, after discounts."
                />

                <div className="grid gap-2 rounded-lg border p-4 text-sm sm:grid-cols-3">
                    <div>
                        <div className="text-muted-foreground">Customer</div>
                        <div className="font-medium">{sale.party?.name ?? 'Walk-in customer'}</div>
                    </div>
                    <div>
                        <div className="text-muted-foreground">Sale total / paid</div>
                        <div className="font-medium tabular-nums">
                            {formatMoney(sale.total)} / {formatMoney(sale.paid_amount)}
                        </div>
                    </div>
                    <div>
                        <div className="text-muted-foreground">Still due on this sale</div>
                        <div className="font-medium tabular-nums">{formatMoney(sale.due_amount)}</div>
                    </div>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-4 py-3 font-medium">Product</th>
                                <th className="px-4 py-3 text-right font-medium">Sold</th>
                                <th className="px-4 py-3 text-right font-medium">Returned</th>
                                <th className="px-4 py-3 text-right font-medium">Paid per unit</th>
                                <th className="w-32 px-4 py-3 font-medium">Return qty</th>
                            </tr>
                        </thead>
                        <tbody>
                            {items.map((item, index) => (
                                <tr key={item.id} className="border-t align-top">
                                    <td className="px-4 py-3">
                                        <div className="font-medium">{item.product?.name}</div>
                                        <div className="text-muted-foreground font-mono text-xs">{item.product?.sku}</div>
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">{item.quantity}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">{item.returned_quantity}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {formatMoney(fromCents(estimate(item.line_total, item.quantity, 1)))}
                                    </td>
                                    <td className="px-4 py-3">
                                        <Input
                                            type="number"
                                            min={0}
                                            max={item.returnable_quantity}
                                            step={1}
                                            value={form.data.items[index].quantity}
                                            onChange={(e) => setQuantity(index, e.target.value)}
                                            disabled={item.returnable_quantity === 0}
                                            aria-label={`Return quantity for ${item.product?.name}`}
                                        />
                                        {item.returnable_quantity === 0 && <p className="text-muted-foreground mt-1 text-xs">Fully returned</p>}
                                        <InputError message={errors[`items.${index}.quantity`] ?? errors[`items.${index}.sale_item_id`]} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <InputError message={errors.items} />
                <InputError message={errors.quantity} />

                <div className="grid gap-6 md:grid-cols-2">
                    <div className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="reason" required>
                                Reason
                            </Label>
                            <Input
                                id="reason"
                                value={form.data.reason}
                                onChange={(e) => form.setData('reason', e.target.value)}
                                placeholder="e.g. Faulty charger"
                                required
                            />
                            <InputError message={errors.reason} />
                        </div>
                        {walkIn ? (
                            <p className="text-muted-foreground rounded-lg border p-3 text-sm">
                                Walk-in sale: the full value is refunded in cash — there is no account to hold credit.
                            </p>
                        ) : (
                            <div className="grid gap-2">
                                <Label htmlFor="refund_amount">Cash refund now</Label>
                                <Input
                                    id="refund_amount"
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    value={form.data.refund_amount}
                                    onChange={(e) => form.setData('refund_amount', e.target.value)}
                                    placeholder="0.00"
                                />
                                <button
                                    type="button"
                                    className="text-muted-foreground text-left text-xs hover:underline"
                                    onClick={() => form.setData('refund_amount', fromCents(paidPartCents))}
                                >
                                    Refund everything already paid ({formatMoney(fromCents(paidPartCents))})
                                </button>
                                <p className="text-muted-foreground text-xs">
                                    Anything not refunded stays on the customer's account as store credit.
                                </p>
                                <InputError message={errors.refund_amount} />
                            </div>
                        )}
                        {(walkIn || toCents(form.data.refund_amount) > 0) && (
                            <div className="grid gap-2">
                                <Label htmlFor="refund_method">Refund method</Label>
                                <select
                                    id="refund_method"
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                    value={form.data.refund_method}
                                    onChange={(e) => form.setData('refund_method', e.target.value)}
                                >
                                    {methods.map((method) => (
                                        <option key={method.value} value={method.value}>
                                            {method.label}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.refund_method} />
                            </div>
                        )}
                    </div>

                    <dl className="space-y-2 rounded-lg border p-4 text-sm">
                        <div className="flex justify-between font-medium">
                            <dt>Return value (estimate)</dt>
                            <dd className="tabular-nums">{formatMoney(fromCents(valueCents))}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt>Reduces amount still due</dt>
                            <dd className="tabular-nums">{formatMoney(fromCents(adjustmentCents))}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt>Already paid — refund or credit</dt>
                            <dd className="tabular-nums">{formatMoney(fromCents(paidPartCents))}</dd>
                        </div>
                        {sale.party && (
                            <div className="flex justify-between border-t pt-2">
                                <dt>Customer balance now</dt>
                                <dd>
                                    <PartyBalance balance={customerBalance ?? '0.00'} />
                                </dd>
                            </div>
                        )}
                    </dl>
                </div>

                <div className="flex gap-2">
                    <Button type="submit" disabled={form.processing || valueCents === 0}>
                        {form.processing && <LoaderCircle className="size-4 animate-spin" />}
                        Record return
                    </Button>
                    <Button variant="outline" asChild>
                        <Link href={route('sales.show', sale.id)}>Cancel</Link>
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
