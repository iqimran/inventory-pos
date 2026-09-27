import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PartyBalance } from '@/features/parties/balance';
import { type SelectOption } from '@/features/purchasing/types';
import { CustomerPicker } from '@/features/sales/customer-picker';
import { type Customer } from '@/features/sales/types';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatMoney } from '@/lib/format';
import { Head, router, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

interface CustomerContext {
    id: number;
    name: string;
    phone: string | null;
    balance: string;
    receivable: string;
    due_sales: { id: number; invoice_no: string; sold_at: string; total: string; due_amount: string }[];
}

interface CollectDueProps {
    methods: SelectOption[];
    customer: CustomerContext | null;
    saleId: number | null;
}

const selectClass = 'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

export default function CollectDue({ methods, customer, saleId }: CollectDueProps) {
    const form = useForm({
        party_id: customer ? String(customer.id) : '',
        sale_id: saleId ? String(saleId) : '',
        amount: '',
        method: 'CASH',
        date: new Date().toISOString().slice(0, 10),
        reference_no: '',
        notes: '',
    });
    const selectedSale = customer?.due_sales.find((sale) => String(sale.id) === form.data.sale_id);

    const selectCustomer = (picked: Customer | null) => {
        form.setData((data) => ({ ...data, party_id: picked ? String(picked.id) : '', sale_id: '' }));
        router.reload({ only: ['customer'], data: { party_id: picked?.id } });
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('customer-payments.store'));
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Customer payments', href: route('customer-payments.index') },
                { title: 'Collect due', href: route('customer-payments.create') },
            ]}
        >
            <Head title="Collect due" />
            <div className="max-w-3xl space-y-6 p-4 md:p-6">
                <Heading title="Collect customer due" description="Receive money against one sale, or on account to clear the oldest dues first." />

                <div className="grid gap-2">
                    <Label>Customer</Label>
                    <CustomerPicker
                        value={customer ? { id: customer.id, name: customer.name, phone: customer.phone, balance: customer.balance } : null}
                        onChange={selectCustomer}
                        allowCreate={false}
                        placeholder="Search customer by name or phone"
                    />
                    <InputError message={form.errors.party_id} />
                </div>

                {customer && (
                    <form onSubmit={submit} className="space-y-6">
                        <div className="flex justify-between rounded-lg border p-4 text-sm">
                            <span>Current balance</span>
                            <PartyBalance balance={customer.balance} className="font-medium" />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="sale_id">Apply to</Label>
                            <select
                                id="sale_id"
                                className={selectClass}
                                value={form.data.sale_id}
                                onChange={(e) => form.setData('sale_id', e.target.value)}
                            >
                                <option value="">On account — oldest dues first</option>
                                {customer.due_sales.map((sale) => (
                                    <option key={sale.id} value={sale.id}>
                                        {sale.invoice_no} ({formatDateTime(sale.sold_at)}) — due {formatMoney(sale.due_amount)}
                                    </option>
                                ))}
                            </select>
                            <InputError message={form.errors.sale_id} />
                        </div>

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
                                <button
                                    type="button"
                                    className="text-muted-foreground text-left text-xs hover:underline"
                                    onClick={() => form.setData('amount', selectedSale ? selectedSale.due_amount : customer.receivable)}
                                >
                                    Full {selectedSale ? 'sale due' : 'receivable'}
                                </button>
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
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="date">Date</Label>
                                <Input id="date" type="date" value={form.data.date} onChange={(e) => form.setData('date', e.target.value)} />
                                <InputError message={form.errors.date} />
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <Input
                                value={form.data.reference_no}
                                onChange={(e) => form.setData('reference_no', e.target.value)}
                                placeholder="Reference (transaction no.)"
                                aria-label="Reference"
                            />
                            <Input
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                                placeholder="Notes"
                                aria-label="Notes"
                            />
                        </div>

                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <LoaderCircle className="size-4 animate-spin" />}
                            Receive payment
                        </Button>
                    </form>
                )}
            </div>
        </AppLayout>
    );
}
