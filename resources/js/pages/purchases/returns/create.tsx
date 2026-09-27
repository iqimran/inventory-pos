import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type Purchase, type SelectOption } from '@/features/purchasing/types';
import AppLayout from '@/layouts/app-layout';
import { formatMoney, fromCents, toCents } from '@/lib/format';
import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

export default function CreatePurchaseReturn({ purchase: { data: purchase }, methods }: { purchase: { data: Purchase }; methods: SelectOption[] }) {
    const items = purchase.items ?? [];
    const form = useForm({
        return_date: new Date().toISOString().slice(0, 10),
        reason: '',
        refund_amount: '0.00',
        refund_method: 'CASH',
        items: items.map((item) => ({ purchase_item_id: item.id, quantity: '0' })),
    });
    const errors = form.errors as Record<string, string>;

    const setQuantity = (index: number, quantity: string) =>
        form.setData(
            'items',
            form.data.items.map((line, i) => (i === index ? { ...line, quantity } : line)),
        );

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('purchases.returns.store', purchase.id));
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Purchases', href: route('purchases.index') },
                { title: purchase.purchase_no, href: route('purchases.show', purchase.id) },
                { title: 'Return', href: route('purchases.returns.create', purchase.id) },
            ]}
        >
            <Head title={`Return — ${purchase.purchase_no}`} />
            <form onSubmit={submit} className="max-w-4xl space-y-6 p-4 md:p-6">
                <Heading
                    title={`Return items from ${purchase.purchase_no}`}
                    description="Returned goods leave stock and reduce what you owe the supplier, valued at their net purchase cost."
                />

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-4 py-3 font-medium">Product</th>
                                <th className="px-4 py-3 text-right font-medium">Purchased</th>
                                <th className="px-4 py-3 text-right font-medium">Returnable</th>
                                <th className="px-4 py-3 text-right font-medium">Net unit cost</th>
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
                                    <td className="px-4 py-3 text-right tabular-nums">{item.returnable_quantity}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {formatMoney(fromCents(Math.round(toCents(item.line_total) / item.quantity)))}
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
                                        <InputError message={errors[`items.${index}.quantity`]} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <InputError message={errors.items} />
                <InputError message={errors.quantity} />

                <div className="grid gap-4 md:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="return_date">Return date</Label>
                        <Input
                            id="return_date"
                            type="date"
                            value={form.data.return_date}
                            onChange={(e) => form.setData('return_date', e.target.value)}
                        />
                        <InputError message={errors.return_date} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="reason">Reason</Label>
                        <Input
                            id="reason"
                            value={form.data.reason}
                            onChange={(e) => form.setData('reason', e.target.value)}
                            placeholder="e.g. Defective units"
                            required
                        />
                        <InputError message={errors.reason} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="refund_amount">Cash refund received (optional)</Label>
                        <Input
                            id="refund_amount"
                            type="number"
                            min={0}
                            step="0.01"
                            value={form.data.refund_amount}
                            onChange={(e) => form.setData('refund_amount', e.target.value)}
                        />
                        <p className="text-muted-foreground text-xs">
                            Only when the supplier pays money back; otherwise the return just reduces the payable.
                        </p>
                        <InputError message={errors.refund_amount} />
                    </div>
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
                    </div>
                </div>

                <div className="flex gap-2">
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <LoaderCircle className="size-4 animate-spin" />}
                        Record return
                    </Button>
                    <Button variant="outline" asChild>
                        <Link href={route('purchases.show', purchase.id)}>Cancel</Link>
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
