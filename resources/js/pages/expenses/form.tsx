import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type Expense } from '@/features/expenses/types';
import { type SelectOption } from '@/features/purchasing/types';
import AppLayout from '@/layouts/app-layout';
import { type Option } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

interface ExpenseFormProps {
    expense: { data: Expense } | null;
    types: Option[];
    methods: SelectOption[];
}

const selectClass = 'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';
const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);

export default function ExpenseForm({ expense: wrapped, types, methods }: ExpenseFormProps) {
    const expense = wrapped?.data ?? null;
    const form = useForm({
        expense_type_id: expense ? String(expense.expense_type_id) : '',
        amount: expense?.amount ?? '',
        expense_date: expense?.expense_date ?? today(),
        payment_method: expense?.payment_method ?? 'CASH',
        reference: expense?.reference ?? '',
        notes: expense?.notes ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (expense) {
            form.put(route('expenses.update', expense.id));
        } else {
            form.post(route('expenses.store'));
        }
    };

    const title = expense ? `Edit ${expense.expense_no}` : 'Record expense';

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Expenses', href: route('expenses.index') },
                { title, href: expense ? route('expenses.edit', expense.id) : route('expenses.create') },
            ]}
        >
            <Head title={title} />
            <form onSubmit={submit} className="max-w-2xl space-y-6 p-4 md:p-6">
                <Heading title={title} description={expense ? 'Changes are recorded in the expense history.' : 'Money spent by the shop.'} />

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="expense_type_id">Expense type</Label>
                        <select
                            id="expense_type_id"
                            className={selectClass}
                            value={form.data.expense_type_id}
                            onChange={(e) => form.setData('expense_type_id', e.target.value)}
                            required
                        >
                            <option value="">Select a type</option>
                            {types.map((type) => (
                                <option key={type.id} value={type.id}>
                                    {type.name}
                                </option>
                            ))}
                        </select>
                        <InputError message={form.errors.expense_type_id} />
                    </div>
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
                        <InputError message={form.errors.amount} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="expense_date">Date</Label>
                        <Input
                            id="expense_date"
                            type="date"
                            max={today()}
                            value={form.data.expense_date}
                            onChange={(e) => form.setData('expense_date', e.target.value)}
                            required
                        />
                        <InputError message={form.errors.expense_date} />
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
                    <Label htmlFor="reference">Reference</Label>
                    <Input
                        id="reference"
                        value={form.data.reference}
                        onChange={(e) => form.setData('reference', e.target.value)}
                        placeholder="Bill / transaction / voucher no."
                    />
                    <InputError message={form.errors.reference} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="notes">Notes</Label>
                    <textarea
                        id="notes"
                        className="border-input bg-background min-h-20 w-full rounded-md border px-3 py-2 text-sm"
                        value={form.data.notes}
                        onChange={(e) => form.setData('notes', e.target.value)}
                    />
                    <InputError message={form.errors.notes} />
                </div>

                <InputError message={(form.errors as Record<string, string>).expense} />

                <div className="flex gap-2">
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <LoaderCircle className="size-4 animate-spin" />}
                        {expense ? 'Save changes' : 'Record expense'}
                    </Button>
                    <Button variant="outline" asChild>
                        <Link href={expense ? route('expenses.show', expense.id) : route('expenses.index')}>Cancel</Link>
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
