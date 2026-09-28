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
    due_service_invoices: { id: number; invoice_no: string; invoiced_at: string; total: string; due_amount: string }[];
}

interface CollectDueProps {
    methods: SelectOption[];
    customer: CustomerContext | null;
    saleId: number | null;
    serviceInvoiceId: number | null;
}

const selectClass = 'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

export default function CollectDue({ methods, customer, saleId, serviceInvoiceId }: CollectDueProps) {
    const form = useForm({
        party_id: customer ? String(customer.id) : '',
        sale_id: saleId ? String(saleId) : '',
        service_invoice_id: serviceInvoiceId ? String(serviceInvoiceId) : '',
        amount: '',
        method: 'CASH',
        date: new Date().toISOString().slice(0, 10),
        reference_no: '',
        notes: '',
    });
    const selectedDocument =
        customer?.due_sales.find((sale) => String(sale.id) === form.data.sale_id) ??
        customer?.due_service_invoices.find((invoice) => String(invoice.id) === form.data.service_invoice_id);
    // Sales and service invoices share one picker: values are "sale:ID" / "service_invoice:ID".
    const target = form.data.sale_id
        ? `sale:${form.data.sale_id}`
        : form.data.service_invoice_id
          ? `service_invoice:${form.data.service_invoice_id}`
          : '';
    const selectTarget = (value: string) => {
        const [type, id = ''] = value.split(':');
        form.setData((data) => ({ ...data, sale_id: type === 'sale' ? id : '', service_invoice_id: type === 'service_invoice' ? id : '' }));
    };

    const selectCustomer = (picked: Customer | null) => {
        form.setData((data) => ({ ...data, party_id: picked ? String(picked.id) : '', sale_id: '', service_invoice_id: '' }));
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
                <Heading
                    title="Collect customer due"
                    description="Receive money against one sale or service invoice, or on account to clear the oldest dues first."
                />

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
                            <select id="sale_id" className={selectClass} value={target} onChange={(e) => selectTarget(e.target.value)}>
                                <option value="">On account — oldest dues first</option>
                                {customer.due_sales.map((sale) => (
                                    <option key={`sale-${sale.id}`} value={`sale:${sale.id}`}>
                                        {sale.invoice_no} ({formatDateTime(sale.sold_at)}) — due {formatMoney(sale.due_amount)}
                                    </option>
                                ))}
                                {customer.due_service_invoices.map((invoice) => (
                                    <option key={`service-${invoice.id}`} value={`service_invoice:${invoice.id}`}>
                                        {invoice.invoice_no} (service, {formatDateTime(invoice.invoiced_at)}) — due {formatMoney(invoice.due_amount)}
                                    </option>
                                ))}
                            </select>
                            <InputError message={form.errors.sale_id ?? form.errors.service_invoice_id} />
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
                                    onClick={() => form.setData('amount', selectedDocument ? selectedDocument.due_amount : customer.receivable)}
                                >
                                    Full {selectedDocument ? 'invoice due' : 'receivable'}
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
