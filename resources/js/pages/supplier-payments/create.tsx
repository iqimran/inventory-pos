import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PartyBalance } from '@/features/parties/balance';
import { type SelectOption } from '@/features/purchasing/types';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Head, router, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

interface SupplierContext {
    id: number;
    name: string;
    balance: string;
    payable: string;
    available_advance: string;
    due_purchases: { id: number; purchase_no: string; purchase_date: string; total: string; due_amount: string }[];
}

interface CreatePaymentProps {
    mode: 'payment' | 'advance';
    suppliers: { id: number; name: string; phone: string | null }[];
    methods: SelectOption[];
    supplier: SupplierContext | null;
    purchaseId: number | null;
}

const selectClass = 'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

export default function CreateSupplierPayment({ mode, suppliers, methods, supplier, purchaseId }: CreatePaymentProps) {
    const form = useForm({
        party_id: supplier ? String(supplier.id) : '',
        purchase_id: purchaseId ? String(purchaseId) : '',
        amount: '',
        method: 'CASH',
        date: new Date().toISOString().slice(0, 10),
        reference_no: '',
        notes: '',
    });

    const selectedPurchase = supplier?.due_purchases.find((p) => String(p.id) === form.data.purchase_id);
    const isAdvance = mode === 'advance';

    const switchMode = (next: 'payment' | 'advance') =>
        router.get(route('supplier-payments.create'), { mode: next, party_id: form.data.party_id || undefined }, { preserveState: false });

    const selectSupplier = (partyId: string) => {
        form.setData((data) => ({ ...data, party_id: partyId, purchase_id: '' }));
        router.reload({ only: ['supplier'], data: { party_id: partyId || undefined } });
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(isAdvance ? route('supplier-advances.store') : route('supplier-payments.store'));
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Supplier payments', href: route('supplier-payments.index') },
                { title: isAdvance ? 'Advance' : 'Payment', href: route('supplier-payments.create') },
            ]}
        >
            <Head title={isAdvance ? 'Supplier advance' : 'Supplier payment'} />
            <div className="max-w-3xl space-y-6 p-4 md:p-6">
                <Heading
                    title={isAdvance ? 'Pay supplier advance' : 'Pay supplier'}
                    description={
                        isAdvance
                            ? 'Money paid before goods are received. It is held on account and can be applied to later purchases.'
                            : 'Settle a specific purchase, or pay on account to clear the oldest dues first.'
                    }
                />

                <div className="inline-flex rounded-lg border p-1">
                    {(['payment', 'advance'] as const).map((option) => (
                        <button
                            key={option}
                            type="button"
                            onClick={() => option !== mode && switchMode(option)}
                            className={cn(
                                'rounded-md px-3 py-1.5 text-sm',
                                option === mode ? 'bg-primary text-primary-foreground' : 'hover:bg-muted',
                            )}
                        >
                            {option === 'payment' ? 'Payment against dues' : 'Advance'}
                        </button>
                    ))}
                </div>

                <form onSubmit={submit} className="space-y-6">
                    <div className="grid gap-2">
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

                    {supplier && (
                        <div className="grid gap-2 rounded-lg border p-4 text-sm sm:grid-cols-3">
                            <div>
                                <div className="text-muted-foreground">Balance</div>
                                <PartyBalance balance={supplier.balance} className="font-medium" />
                            </div>
                            <div>
                                <div className="text-muted-foreground">Payable</div>
                                <div className="font-medium tabular-nums">{formatMoney(supplier.payable)}</div>
                            </div>
                            <div>
                                <div className="text-muted-foreground">Unapplied advance</div>
                                <div className="font-medium tabular-nums">{formatMoney(supplier.available_advance)}</div>
                            </div>
                        </div>
                    )}

                    {!isAdvance && supplier && (
                        <div className="grid gap-2">
                            <Label htmlFor="purchase_id">Apply to</Label>
                            <select
                                id="purchase_id"
                                className={selectClass}
                                value={form.data.purchase_id}
                                onChange={(e) => form.setData('purchase_id', e.target.value)}
                            >
                                <option value="">On account — oldest dues first</option>
                                {supplier.due_purchases.map((purchase) => (
                                    <option key={purchase.id} value={purchase.id}>
                                        {purchase.purchase_no} ({purchase.purchase_date}) — due {formatMoney(purchase.due_amount)}
                                    </option>
                                ))}
                            </select>
                            <InputError message={form.errors.purchase_id} />
                        </div>
                    )}

                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="amount">Amount</Label>
                            <Input
                                id="amount"
                                type="number"
                                min="0.01"
                                step="0.01"
                                value={form.data.amount}
                                onChange={(e) => form.setData('amount', e.target.value)}
                                required
                            />
                            {!isAdvance && (selectedPurchase || supplier) && (
                                <button
                                    type="button"
                                    className="text-muted-foreground text-left text-xs hover:underline"
                                    onClick={() => form.setData('amount', selectedPurchase ? selectedPurchase.due_amount : (supplier?.payable ?? ''))}
                                >
                                    Pay full {selectedPurchase ? 'purchase due' : 'payable'}
                                </button>
                            )}
                            <InputError message={form.errors.amount} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="method">Method</Label>
                            <select
                                id="method"
                                className={selectClass}
                                value={form.data.method}
                                onChange={(e) => form.setData('method', e.target.value)}
                            >
                                {methods.map((method) => (
                                    <option key={method.value} value={method.value}>
                                        {method.label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={form.errors.method} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="date">Date</Label>
                            <Input id="date" type="date" value={form.data.date} onChange={(e) => form.setData('date', e.target.value)} />
                            <InputError message={form.errors.date} />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="reference_no">Reference</Label>
                            <Input
                                id="reference_no"
                                value={form.data.reference_no}
                                onChange={(e) => form.setData('reference_no', e.target.value)}
                                placeholder="Transaction / cheque no."
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="notes">Notes</Label>
                            <Input id="notes" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                        </div>
                    </div>

                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <LoaderCircle className="size-4 animate-spin" />}
                        {isAdvance ? 'Record advance' : 'Record payment'}
                    </Button>
                </form>
            </div>
        </AppLayout>
    );
}
